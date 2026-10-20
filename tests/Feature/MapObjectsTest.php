<?php

use Goldnead\BrandContext\Map\MapLookup;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\Courses\Integrations\Map\CourseMapObjects;
use Goldnead\Courses\Models\Enrollment;
use Illuminate\Support\Carbon;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * The funnels map's view of a course (statamic-brand-context 1.17). The map
 * stores type + id only; label, link, on/off and the number come from here.
 */

beforeEach(function () {
    $this->chorId = $this->makeCourse('cvt-im-chor-minikurs', ['title' => 'CVT im Chor Minikurs']);
    $this->stimmId = $this->makeCourse('stimme-basics', ['title' => 'Stimme Basics']);
    $this->map = app(CourseMapObjects::class);
});

/** An enrollment with a set creation time; `created_at` is not mass-assignable. */
function mapTestEnroll(string $courseId, string $userId, Carbon $createdAt): void
{
    (new Enrollment)
        ->forceFill(['user_id' => $userId, 'course_entry_id' => $courseId, 'created_at' => $createdAt, 'updated_at' => $createdAt])
        ->save();
}

it('lists courses by title and narrows them by title or slug, limited', function () {
    expect(array_map(fn ($o) => $o->label, $this->map->objects()))
        ->toBe(['CVT im Chor Minikurs', 'Stimme Basics']);

    $byTitle = $this->map->objects('stimme');
    expect($byTitle)->toHaveCount(1)
        ->and($byTitle[0]->id)->toBe('stimme-basics');

    // The slug has hyphens the title does not, so only the slug matches here.
    $bySlug = $this->map->objects('cvt-im');
    expect($bySlug)->toHaveCount(1)
        ->and($bySlug[0]->id)->toBe('cvt-im-chor-minikurs');

    expect($this->map->objects(null, 1))->toHaveCount(1)
        ->and($this->map->objects('gibt es nicht'))->toBe([]);
});

it('leaves material entries out: they share the collection but are not courses', function () {
    $this->makeCourse('arbeitsblatt', ['title' => 'Arbeitsblatt', 'kind' => 'material']);

    expect(array_map(fn ($o) => $o->id, $this->map->objects()))->not->toContain('arbeitsblatt')
        ->and($this->map->find('arbeitsblatt'))->toBeNull();
});

it('finds one course by slug, or null when it is not there', function () {
    $found = $this->map->find('stimme-basics');

    expect($found->type)->toBe('course')
        ->and($found->id)->toBe('stimme-basics')
        ->and($found->label)->toBe('Stimme Basics')
        ->and($found->detail)->toBeNull()
        ->and($this->map->find('gibt-es-nicht'))->toBeNull()
        ->and($this->map->find(''))->toBeNull();
});

it('maps published to enabled and links to the entry in the control panel', function () {
    expect($this->map->find('stimme-basics')->enabled)->toBeTrue()
        ->and($this->map->find('stimme-basics')->cpUrl)->toContain((string) $this->stimmId);

    Entry::find($this->stimmId)->published(false)->save();

    expect($this->map->find('stimme-basics')->enabled)->toBeFalse();
});

it('counts only enrollments of this course created in the period', function () {
    $period = MapPeriod::lastDays(30);

    mapTestEnroll($this->chorId, 'user-1', Carbon::now()->subDays(2));
    mapTestEnroll($this->chorId, 'user-2', Carbon::now()->subDays(10));
    mapTestEnroll($this->chorId, 'user-3', Carbon::now()->subDays(45));
    mapTestEnroll($this->stimmId, 'user-4', Carbon::now()->subDays(2));

    $metric = $this->map->metric('cvt-im-chor-minikurs', $period);

    expect($metric->value)->toBe(2)
        ->and($metric->what)->toBe('Started')
        ->and($metric->period)->toBe($period);
});

it('has no number for a course that does not exist', function () {
    expect($this->map->metric('gibt-es-nicht', MapPeriod::lastDays(30)))->toBeNull();
});

it('is registered on boot and answers through the registry', function () {
    $registry = app(MapObjectRegistry::class);

    expect($registry->has('course'))->toBeTrue()
        ->and($registry->types())->toHaveKey('course', 'Course')
        ->and($registry->objects('course', 'stimme'))->toHaveCount(1)
        ->and($registry->lookup('course', 'stimme-basics')->status)->toBe(MapLookup::FOUND)
        ->and($registry->lookup('course', 'gibt-es-nicht')->status)->toBe(MapLookup::MISSING);
});

it('details: a facts block with the lesson count and a list block of lessons', function () {
    $this->makeLesson($this->chorId, 'zweite', ['title' => 'Zweite', 'section_title' => 'Modul B', 'section_order' => 2, 'sort_order' => 1]);
    $this->makeLesson($this->chorId, 'erste', ['title' => 'Erste', 'section_title' => 'Modul A', 'section_order' => 1, 'sort_order' => 1]);
    $this->makeLesson($this->chorId, 'ohne', ['title' => 'Ohne Modul', 'section_order' => 3]);
    $this->makeLesson($this->stimmId, 'fremd', ['title' => 'Fremd']);

    $blocks = $this->map->details('cvt-im-chor-minikurs', MapPeriod::lastDays(30));

    expect($blocks)->toHaveCount(2)
        ->and($blocks[0]['kind'])->toBe('facts')
        ->and($blocks[0]['rows'][0]['value'])->toBe('3')
        ->and($blocks[1]['kind'])->toBe('list')
        ->and(array_column($blocks[1]['items'], 'title'))->toBe(['Erste', 'Zweite', 'Ohne Modul'])
        ->and($blocks[1]['items'][0]['meta'])->toBe('Modul A')
        ->and($blocks[1]['items'][2]['meta'])->toBeNull()
        ->and($blocks[1]['items'][0]['image'] ?? null)->toBeNull()
        ->and($blocks[1]['more'] ?? null)->toBeNull();
});

it('details: shows at most 12 lessons and counts the rest in more', function () {
    foreach (range(1, 15) as $n) {
        $this->makeLesson($this->chorId, "l{$n}", ['title' => "L{$n}", 'sort_order' => $n]);
    }

    $blocks = $this->map->details('cvt-im-chor-minikurs', MapPeriod::lastDays(30));

    expect($blocks[0]['rows'][0]['value'])->toBe('15')
        ->and($blocks[1]['items'])->toHaveCount(12)
        ->and($blocks[1]['more'])->toBe(3);
});

it('details: a course without lessons has only the facts, material and unknown ids give null', function () {
    $blocks = $this->map->details('stimme-basics', MapPeriod::lastDays(30));

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['kind'])->toBe('facts')
        ->and($blocks[0]['rows'][0]['value'])->toBe('0');

    $this->makeCourse('arbeitsblatt', ['title' => 'Arbeitsblatt', 'kind' => 'material']);

    expect($this->map->details('arbeitsblatt', MapPeriod::lastDays(30)))->toBeNull()
        ->and($this->map->details('gibt-es-nicht', MapPeriod::lastDays(30)))->toBeNull();
});

it('details: a draft lesson is neither listed nor counted', function () {
    $this->makeLesson($this->chorId, 'live', ['title' => 'Live']);
    $draft = Entry::make()->collection('course_lessons')->slug('entwurf')->published(false)
        ->data(['title' => 'Entwurf', 'course' => $this->chorId]);
    $draft->save();

    $blocks = $this->map->details('cvt-im-chor-minikurs', MapPeriod::lastDays(30));

    expect($blocks[0]['rows'][0]['value'])->toBe('1')
        ->and(array_column($blocks[1]['items'], 'title'))->toBe(['Live']);
});

it('detailsAuthorized: only a user who may view the course entries, closed otherwise', function () {
    $viewer = tap(User::make()->id('viewer')->email('viewer@example.test'))->save();
    Role::make('course-viewer')->addPermission('view courses entries')->save();
    $viewer->assignRole('course-viewer')->save();

    $other = tap(User::make()->id('nobody')->email('nobody@example.test'))->save();

    expect($this->map->detailsAuthorized('cvt-im-chor-minikurs', $viewer))->toBeTrue()
        ->and($this->map->detailsAuthorized('cvt-im-chor-minikurs', $other))->toBeFalse()
        ->and($this->map->detailsAuthorized('cvt-im-chor-minikurs', null))->toBeFalse();
});
