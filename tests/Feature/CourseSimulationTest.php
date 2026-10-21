<?php

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\Courses\Integrations\Map\CourseMapObjects;
use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Progress\DripSchedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * The dry run of a course: what a learner who gets access sees of the lessons, each on its
 * own drip clock. Nothing is enrolled, opened, written or sent, and the tests hold the line on that.
 *
 * Needs the simulation classes of statamic-brand-context; skipped without them.
 */

beforeEach(function () {
    if (! class_exists(Effect::class)) {
        $this->markTestSkipped('needs the simulation classes of statamic-brand-context (Effect, SimEvent, SimPerson)');
    }

    $this->map = app(CourseMapObjects::class);
    $this->at = CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC');
});

/** A course with lessons in the given order; each lesson is [slug, data]. */
function simCourse(object $test, string $slug, string $mode, array $lessons, array $course = []): void
{
    $id = $test->makeCourse($slug, ['title' => 'Stimme '.$slug, 'drip_mode' => $mode, ...$course]);

    foreach (array_values($lessons) as $i => [$lessonSlug, $data]) {
        $test->makeLesson($id, $lessonSlug, ['sort_order' => $i + 1, ...$data]);
    }
}

/** The effects a learner sees of the course's lessons (the first effect is the access). */
function simLessons(object $test, string $slug): array
{
    return array_slice($test->map->react($slug, new SimEvent(SimEvent::GRANTED), SimPerson::known(), $test->at), 1);
}

it('shows the course as unlocked, then each lesson on its own clock (days)', function () {
    simCourse($this, 'tage', 'days', [['sofort', []], ['tag-drei', ['drip_after' => 3]]]);

    $effects = $this->map->react('tage', new SimEvent(SimEvent::GRANTED), SimPerson::known(), $this->at);

    expect($effects)->toHaveCount(3)
        ->and($effects[0]->kind)->toBe(Effect::ACCESS)
        ->and($effects[0]->meta)->toBe(['course' => 'tage'])
        ->and($effects[1]->kind)->toBe(Effect::PAGE)
        ->and($effects[1]->label)->toBe('Sofort')
        ->and($effects[1]->afterSeconds)->toBe(0)
        ->and($effects[1]->meta)->toBe(['lesson' => 'sofort', 'course' => 'tage'])
        ->and($effects[2]->kind)->toBe(Effect::PAGE)
        ->and($effects[2]->afterSeconds)->toBe(3 * 86400)
        ->and($effects[2]->meta)->toBe(['lesson' => 'tag-drei', 'course' => 'tage']);
});

it('counts a schedule week from the start: week 3 is 14 days', function () {
    simCourse($this, 'wochen', 'schedule', [['woche-eins', ['week' => 1]], ['woche-drei', ['week' => 3]]]);

    $lessons = simLessons($this, 'wochen');

    expect($lessons[0]->afterSeconds)->toBe(0)
        ->and($lessons[1]->afterSeconds)->toBe(14 * 86400);
});

it('opens a lesson on a fixed date, counted in the display timezone', function () {
    simCourse($this, 'datum', 'date', [['am-datum', ['drip_date' => '2026-11-15']]]);

    $day = CarbonImmutable::parse('2026-11-15', DripSchedule::calendarTimezone())->startOfDay();
    $lesson = simLessons($this, 'datum')[0];

    expect($lesson->kind)->toBe(Effect::PAGE)
        ->and($lesson->afterSeconds)->toBe($day->getTimestamp() - $this->at->getTimestamp())
        ->and($lesson->detail)->toBe(__('courses::map.sim_on_date', ['date' => '15.11.2026']));
});

it('shows a payment drip as unknown: no date can be promised', function () {
    simCourse($this, 'zahlung', 'payments', [['nach-zahlung', ['drip_after' => 2]], ['vorab', []]]);

    $lessons = simLessons($this, 'zahlung');

    expect($lessons[0]->kind)->toBe(Effect::UNKNOWN)
        ->and($lessons[0]->afterSeconds)->toBe(0)
        ->and($lessons[0]->detail)->toBe(__('courses::map.sim_after_payment'))
        ->and($lessons[0]->meta)->toBe(['lesson' => 'nach-zahlung', 'course' => 'zahlung'])
        ->and($lessons[1]->kind)->toBe(Effect::PAGE)
        ->and($lessons[1]->afterSeconds)->toBe(0);
});

it('opens every lesson at once without a drip, in sort order', function () {
    simCourse($this, 'ohne', 'none', [['zweite', ['sort_order' => 2]], ['erste', ['sort_order' => 1]]]);

    $lessons = simLessons($this, 'ohne');

    expect(array_column(array_map(fn (Effect $e) => $e->meta, $lessons), 'lesson'))->toBe(['erste', 'zweite'])
        ->and(array_unique(array_map(fn (Effect $e) => $e->afterSeconds, $lessons)))->toBe([0]);
});

it('leaves drafts out of the dry run', function () {
    $id = $this->makeCourse('entwurf', ['title' => 'Entwurf-Kurs', 'drip_mode' => 'none']);
    $this->makeLesson($id, 'veroeffentlicht');
    Entry::make()->collection('course_lessons')->slug('entwurf-lektion')->published(false)
        ->data(['title' => 'Entwurf', 'course' => $id, 'sort_order' => 2])
        ->save();

    $lessons = simLessons($this, 'entwurf');

    expect(array_column(array_map(fn (Effect $e) => $e->meta, $lessons), 'lesson'))->toBe(['veroeffentlicht']);
});

it('names the lessons beyond forty in one line', function () {
    $lessons = [];

    for ($i = 1; $i <= 42; $i++) {
        $lessons[] = ['lektion-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), []];
    }

    simCourse($this, 'lang', 'none', $lessons);

    $effects = simLessons($this, 'lang');
    $rest = end($effects);

    expect($effects)->toHaveCount(41)
        ->and(array_filter($effects, fn (Effect $e) => $e->kind === Effect::PAGE))->toHaveCount(40)
        ->and($rest->kind)->toBe(Effect::UNKNOWN)
        ->and($rest->meta)->toBe([])
        ->and($rest->label)->toBe(trans_choice('courses::map.sim_more', 2, ['count' => 2]));
});

it('answers unknown for a course that does not exist', function () {
    $effects = $this->map->react('gibt-es-nicht', new SimEvent(SimEvent::GRANTED), SimPerson::known(), $this->at);

    expect($effects)->toHaveCount(1)
        ->and($effects[0]->kind)->toBe(Effect::UNKNOWN);
});

it('writes nothing: no enrollment, no mail, no job', function () {
    simCourse($this, 'schreibt', 'days', [['eins', []], ['drei', ['drip_after' => 3]]]);
    simCourse($this, 'zahlt', 'payments', [['nach', ['drip_after' => 1]]]);

    // Faked after the setup: saving the courses above fires Statamic's own queued listeners.
    Mail::fake();
    Queue::fake();

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements) {
        $statements[] = $query->sql;
    });

    // Read inside the listener: a course is read from the stache, not the database, so
    // this count is the one read that proves the listener sees the statements at all.
    $enrollments = Enrollment::query()->count();

    foreach (['schreibt', 'zahlt', 'gibt-es-nicht'] as $id) {
        $this->map->paths($id);

        foreach ([SimPerson::unknown(), SimPerson::known(), SimPerson::known([], [$id])] as $person) {
            $this->map->react($id, new SimEvent(SimEvent::GRANTED), $person, $this->at);
        }
    }

    $writes = array_values(array_filter(
        $statements,
        fn (string $sql) => ! preg_match('/^\s*(select|pragma)\b/i', $sql),
    ));

    // The listener must have seen the reads, or the empty list proves nothing.
    expect($statements)->not->toBe([])
        ->and($writes)->toBe([])
        ->and(Enrollment::query()->count())->toBe($enrollments);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Queue::assertNothingPushed();
});

it('a course never starts by itself', function () {
    simCourse($this, 'start', 'none', [['eins', []]]);

    expect($this->map->paths('start'))->toBe([]);
});

it('simulationAuthorized: without a user no, without the right no, with the right yes', function () {
    simCourse($this, 'recht', 'none', [['eins', []]]);

    $viewer = tap(User::make()->id('viewer')->email('viewer@example.test'))->save();
    Role::make('course-sim-viewer')->addPermission('view courses entries')->save();
    $viewer->assignRole('course-sim-viewer')->save();

    $other = tap(User::make()->id('nobody')->email('nobody@example.test'))->save();

    expect($this->map->simulationAuthorized('recht', null))->toBeFalse()
        ->and($this->map->simulationAuthorized('recht', $other))->toBeFalse()
        ->and($this->map->simulationAuthorized('recht', $viewer))->toBeTrue();
});
