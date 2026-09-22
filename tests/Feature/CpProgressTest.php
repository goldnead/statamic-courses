<?php

use Goldnead\Courses\Facades\Courses;
use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Models\LessonState;
use Goldnead\Courses\Support\ProgressReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Statamic\Facades\Collection;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;

function cpUser(array $permissions, bool $super = false): Statamic\Contracts\Auth\User
{
    $handle = 'role-'.Str::random(8);
    Role::make($handle)->addPermission(['access cp', ...$permissions])->save();

    $user = User::make()->email(Str::random(8).'@example.test')->assignRole($handle);

    if ($super) {
        $user->makeSuper();
    }

    $user->save();

    return $user;
}

beforeEach(function () {
    config()->set('statamic.editions.pro', true);

    Carbon::setTestNow(Carbon::parse('2026-09-22 12:00:00', 'UTC'));

    $cvt = $this->makeCourse('cvt-101', ['title' => 'CVT 101']);
    $this->makeLesson($cvt, 'a', ['sort_order' => 1, 'item_type' => 'text']);
    $this->makeLesson($cvt, 'b', ['sort_order' => 2, 'item_type' => 'text']);
    $this->makeCourse('choir', ['title' => 'Choir']);

    // done: both lessons. active: one lesson today. idle: one lesson 20 days ago.
    Courses::acknowledgeLesson('done', 'cvt-101', 'a');
    Courses::acknowledgeLesson('done', 'cvt-101', 'b');
    Courses::acknowledgeLesson('active', 'cvt-101', 'a');
    Carbon::setTestNow(Carbon::parse('2026-09-02 12:00:00', 'UTC'));
    Courses::acknowledgeLesson('idle', 'cvt-101', 'a');
    Carbon::setTestNow(Carbon::parse('2026-09-22 12:00:00', 'UTC'));
    Courses::enroll('enrolled-only', 'cvt-101');
});

afterEach(fn () => Carbon::setTestNow());

it('reports learners, completion rate and stuck learners per course', function () {
    $rows = collect(app(ProgressReport::class)->overview())->keyBy('slug');

    expect($rows['cvt-101'])->toMatchArray([
        'title' => 'CVT 101',
        'learners' => 4,
        'completed' => 1,
        'in_progress' => 2,
        'completion_rate' => 25,
        'stuck' => 1,
    ])
        ->and(Carbon::parse($rows['cvt-101']['last_activity_at'])->utc()->toDateTimeString())->toBe('2026-09-22 12:00:00')
        ->and($rows['choir'])->toMatchArray(['learners' => 0, 'completion_rate' => 0, 'last_activity_at' => null]);
});

it('does not query once per learner', function () {
    $drip = $this->makeCourse('drip', ['title' => 'Drip', 'drip_mode' => 'schedule']);
    $this->makeLesson($drip, 'w1', ['item_type' => 'text', 'week' => 1]);

    $queriesFor = function (int $learners): int {
        foreach (range(1, $learners) as $i) {
            Courses::enroll("q-{$learners}-{$i}", 'drip');
            Courses::acknowledgeLesson("q-{$learners}-{$i}", 'drip', 'w1');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ProgressReport::class)->overview();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $withTwo = $queriesFor(2);
    $withTen = $queriesFor(8); // 2 + 8 = 10 learners on the drip course

    expect($withTen)->toBe($withTwo);
});

it('takes the stuck threshold from config', function () {
    config()->set('courses.cp.stuck_after_days', 30);

    expect(collect(app(ProgressReport::class)->overview())->firstWhere('slug', 'cvt-101')['stuck'])->toBe(0);
});

it('shows the page to a user with the permission', function () {
    $this->actingAs(cpUser(['view course progress']))
        ->get(cp_route('courses.progress.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('courses::Progress/Index')
            ->where('hasLearners', true)
            ->has('rows', 2)
            ->has('initialColumns', 7));
});

it('links each course to its entry and the page to the collection', function () {
    $this->actingAs(cpUser(['view course progress']))
        ->get(cp_route('courses.progress.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hasCollection', true)
            ->where('locale', 'en')
            ->where('collectionUrl', cp_route('collections.show', 'courses'))
            ->where('rows.0.edit_url', fn ($url) => str_contains((string) $url, '/collections/courses/entries/')));
});

it('shows the table, not the install hint, when courses exist but nobody has started', function () {
    LessonState::query()->delete();
    Enrollment::query()->delete();

    $this->actingAs(cpUser(['view course progress']))
        ->get(cp_route('courses.progress.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hasCollection', true)
            ->where('hasLearners', false)
            ->has('rows', 2));
});

it('offers the install hint only when the course collection does not exist', function () {
    Collection::find('courses')->delete();

    $this->actingAs(cpUser(['view course progress']))
        ->get(cp_route('courses.progress.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hasCollection', false));
});

it('refuses a CP user without the permission', function () {
    // Core turns a failed `can:` in the CP into a redirect with an error
    // toast rather than a bare 403. Either way the page must not render.
    $response = $this->actingAs(cpUser([]))->get(cp_route('courses.progress.index'));

    expect($response->status())->toBeIn([302, 403])
        ->and($response->headers->get('X-Inertia'))->toBeNull();
});

it('sends a guest to the login', function () {
    $this->get(cp_route('courses.progress.index'))->assertRedirect();
});

it('registers the permission', function () {
    Permission::boot();

    expect(Permission::get('view course progress'))->not->toBeNull();
});

it('adds the nav item under Content without the suite section, gated by the permission', function () {
    expect($this->navCallbacks)->toHaveCount(1);

    $nav = new class
    {
        public array $items = [];

        public function create(string $name): object
        {
            return $this->items[] = new class($name)
            {
                public array $calls = [];

                public function __construct(public string $name) {}

                public function __call($method, $args)
                {
                    $this->calls[$method] = $args[0] ?? null;

                    return $this;
                }
            };
        }
    };

    ($this->navCallbacks[0])($nav);

    expect($nav->items[0]->calls)->toMatchArray([
        'section' => 'Content',
        'route' => 'courses.progress.index',
        'can' => 'view course progress',
        // Not chart-monitoring-indicator: insights' "Auswertung" wears that one.
        'icon' => 'content-book-open',
    ]);
});
