<?php

namespace Goldnead\Courses\Integrations\Map;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Progress\DripSchedule;
use Statamic\Entries\Entry as StatamicEntry;

/**
 * What a course would do in a dry run. Part of {@see CourseMapObjects}: the
 * map registry finds these methods with `method_exists`, so they live on the provider.
 *
 * Nothing is enrolled, opened, written or sent. The course and its lessons are read,
 * and the drip is worked out with the pure {@see DripSchedule::gate()} on an enrollment
 * that exists only in memory (started at the dry run's moment, never saved). The lessons
 * are siblings: the course is unlocked, and each lesson opens on its own clock.
 *
 * @phpstan-require-extends CourseMapObjects
 */
trait SimulatesCourse
{
    /** Lessons listed one by one; the rest of a long course is one line. */
    public const SIM_LESSONS = 40;

    /** A course never starts by itself: a person reaches it over the connection that unlocks it. */
    public function paths(string $id): array
    {
        return [];
    }

    /** The dry run shows the lesson titles, so it is as guarded as the panel details. */
    public function simulationAuthorized(string $id, $user): bool
    {
        return $this->detailsAuthorized($id, $user);
    }

    /** @return list<Effect> */
    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        $entry = $this->entry($id);

        if (! $entry instanceof StatamicEntry) {
            return [Effect::unknown(__('courses::map.sim_missing'))];
        }

        $course = $this->courses->normalizeCourse($entry);
        $title = (string) ($entry->get('title') ?: $entry->slug());
        $lessons = $this->publishedLessons($this->courses->lessonsFor((string) $entry->id()));

        // Only the start is set, and the enrollment is never saved: the drip counts from the dry run's moment.
        $enrollment = new Enrollment(['started_at' => $at]);
        $drip = app(DripSchedule::class);

        $effects = [Effect::access(
            __('courses::map.sim_access', ['title' => $title]),
            null,
            ['course' => $id],
        )];

        foreach ($lessons->take(self::SIM_LESSONS) as $lesson) {
            $effects[] = $this->simLesson($id, $course, $lesson, $enrollment, $drip, $at);
        }

        $rest = $lessons->count() - self::SIM_LESSONS;

        if ($rest > 0) {
            $effects[] = new Effect(Effect::UNKNOWN, trans_choice('courses::map.sim_more', $rest, ['count' => $rest]));
        }

        return $effects;
    }

    /**
     * One lesson as the learner would see it: open at once, open after a wait, or open on a day
     * the dry run cannot know (after a payment, or when the drip has no date to promise).
     *
     * @param  array<string, mixed>  $course  normalised course, as LockResolver hands it to the drip
     * @param  array<string, mixed>  $lesson  normalised lesson
     */
    protected function simLesson(string $id, array $course, array $lesson, Enrollment $enrollment, DripSchedule $drip, CarbonImmutable $at): Effect
    {
        $title = (string) ($lesson['title'] ?: $lesson['slug']);
        $meta = ['lesson' => $lesson['slug'], 'course' => $id];

        // After a trial: open at once for someone who bought without one, after the first charge for someone who
        // did. Whether the person has a trial depends on the product she buys, which the course does not know.
        if (($course['drip_mode'] ?? null) === 'after_trial' && (int) ($lesson['drip_after'] ?? 0) >= 1) {
            return new Effect(Effect::UNKNOWN, $title, __('courses::map.sim_after_trial'), 0, $meta);
        }

        $gate = $drip->gate($course, $lesson, $enrollment, $at);

        if (! $gate['locked']) {
            return new Effect(Effect::PAGE, $title, __('courses::map.sim_now'), 0, $meta);
        }

        if ($gate['opens_at'] === null) {
            $detail = $gate['reason'] === 'payment'
                ? __('courses::map.sim_after_payment')
                : __('courses::map.sim_unknown_wait');

            return new Effect(Effect::UNKNOWN, $title, $detail, 0, $meta);
        }

        $opensAt = CarbonImmutable::instance($gate['opens_at']);
        $seconds = max(0, $opensAt->getTimestamp() - $at->getTimestamp());

        return new Effect(Effect::PAGE, $title, $this->simWhen((string) $course['drip_mode'], $opensAt, $seconds), $seconds, $meta);
    }

    /** The detail of a waiting lesson: a calendar day for the date modes, else the days to wait. */
    protected function simWhen(string $mode, CarbonImmutable $opensAt, int $seconds): string
    {
        if (in_array($mode, ['date', 'day_of_month'], true)) {
            return __('courses::map.sim_on_date', ['date' => $opensAt->format('d.m.Y')]);
        }

        $days = intdiv($seconds, 86400);

        return $days < 1
            ? __('courses::map.sim_under_day')
            : trans_choice('courses::map.sim_after_days', $days, ['count' => $days]);
    }
}
