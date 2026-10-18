<?php

use Goldnead\BrandContext\Map\MapLookup;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\Courses\Integrations\Map\CourseMapObjects;
use Goldnead\Courses\Models\Enrollment;
use Illuminate\Support\Carbon;
use Statamic\Facades\Entry;

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
