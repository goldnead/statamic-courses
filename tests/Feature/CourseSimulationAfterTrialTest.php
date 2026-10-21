<?php

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\Courses\Integrations\Map\CourseMapObjects;

/*
 * A drip that opens "after the trial": open at once for someone who bought without a trial, after the first
 * charge for someone who did. Whether the person has a trial depends on the product, which the course does
 * not know, so the dry run says so instead of choosing.
 */

beforeEach(function () {
    if (! class_exists(Effect::class)) {
        $this->markTestSkipped('needs the simulation classes of statamic-brand-context');
    }
});

it('does not promise "at once" for a lesson that waits for the first charge after a trial', function () {
    $id = $this->makeCourse('probe', ['title' => 'Probekurs', 'drip_mode' => 'after_trial']);
    $this->makeLesson($id, 'gleich', ['sort_order' => 1]);
    $this->makeLesson($id, 'nach-probezeit', ['sort_order' => 2, 'drip_after' => 1]);

    $effects = app(CourseMapObjects::class)->react('probe', new SimEvent(SimEvent::GRANTED), SimPerson::known(), CarbonImmutable::parse('2026-10-21 10:00:00'));
    $lessons = array_values(array_filter($effects, fn (Effect $e) => isset($e->meta['lesson'])));

    expect($lessons[0]->kind)->toBe(Effect::PAGE)
        ->and($lessons[1]->kind)->toBe(Effect::UNKNOWN)
        ->and($lessons[1]->detail)->toBe(__('courses::map.sim_after_trial'));
});
