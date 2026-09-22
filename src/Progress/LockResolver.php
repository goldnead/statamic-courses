<?php

namespace Goldnead\Courses\Progress;

use Carbon\CarbonInterface;
use Goldnead\Courses\Enums\LessonStatus;
use Goldnead\Courses\Models\Enrollment;
use Illuminate\Support\Collection;

/**
 * Which lessons a learner may open, and which milestones they have reached.
 *
 * Pure: it reads a lesson list and a progress map and writes nothing. The
 * gates, in the order they are applied:
 *
 * 1. `sequencing_mode` on the course (none, lesson by lesson, section by section)
 * 2. per-lesson prerequisites, an arbitrary DAG
 * 3. phases: a lesson in phase N waits for every earlier phase
 * 4. schedule drip: a lesson in week N waits until week N of the enrollment
 *
 * and finally: a completed lesson is never locked, so it can be revisited.
 *
 * Gates 1 to 3 and the final rule are extracted from adriangoldner.com's
 * CourseProgressService (lessonLockMap, baseLockMap, applyMilestoneAutoCompletion,
 * milestonePrerequisitesMet). Gate 4 is new, see docs/EXTRACTION.md.
 */
class LockResolver
{
    /**
     * A milestone completes itself once its prerequisites are complete, or, with
     * none named, once every other lesson of its phase is. Derived on every read,
     * never written, so locking, rollup and the path always agree.
     *
     * @param  Collection<int, array<string, mixed>>  $lessons
     * @param  array<string, array<string, mixed>>  $progressMap
     * @return array<string, array<string, mixed>>
     */
    public function applyMilestoneAutoCompletion(Collection $lessons, array $progressMap): array
    {
        $isCompleted = $this->completedCheck($progressMap);

        foreach ($lessons as $lesson) {
            if (($lesson['item_type'] ?? null) !== 'milestone' || $isCompleted($lesson['slug'])) {
                continue;
            }

            if (! $this->milestonePrerequisitesMet($lesson, $lessons, $isCompleted)) {
                continue;
            }

            $entry = $progressMap[$lesson['slug']] ?? LessonProgress::empty($lesson);
            $entry['status'] = LessonStatus::Completed->value;
            $entry['completion_percent'] = 100;
            $entry['completion_source'] = 'auto';
            $progressMap[$lesson['slug']] = $entry;
        }

        return $progressMap;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lessons
     * @param  array<string, array<string, mixed>>  $progressMap
     * @return array<string, bool>
     */
    public function lockMap(
        Collection $lessons,
        array $progressMap,
        string $sequencingMode,
        string $dripMode = 'none',
        ?Enrollment $enrollment = null,
        ?CarbonInterface $now = null,
    ): array {
        $lockMap = $this->baseLockMap($lessons, $progressMap, $sequencingMode);
        $isCompleted = $this->completedCheck($progressMap);

        // Gate 2: prerequisites.
        foreach ($lessons as $lesson) {
            foreach ($lesson['prerequisite_slugs'] ?? [] as $prerequisite) {
                if (! $isCompleted((string) $prerequisite)) {
                    $lockMap[$lesson['slug']] = true;
                    break;
                }
            }
        }

        // Gate 3: phases.
        $phaseComplete = [];
        foreach ($lessons as $lesson) {
            $order = $lesson['phase_order'] ?? null;
            if ($order === null) {
                continue;
            }
            $phaseComplete[$order] ??= true;
            if (! $isCompleted($lesson['slug'])) {
                $phaseComplete[$order] = false;
            }
        }
        foreach ($lessons as $lesson) {
            $order = $lesson['phase_order'] ?? null;
            if ($order === null) {
                continue;
            }
            foreach ($phaseComplete as $otherOrder => $complete) {
                if ($otherOrder < $order && ! $complete) {
                    $lockMap[$lesson['slug']] = true;
                    break;
                }
            }
        }

        // Gate 4: schedule.
        if ($dripMode === 'schedule') {
            $openWeek = $this->openWeek($enrollment, $now);
            foreach ($lessons as $lesson) {
                $week = $lesson['week'] ?? null;
                if ($week !== null && $week > $openWeek) {
                    $lockMap[$lesson['slug']] = true;
                }
            }
        }

        foreach ($lessons as $lesson) {
            if ($isCompleted($lesson['slug'])) {
                $lockMap[$lesson['slug']] = false;
            }
        }

        return $lockMap;
    }

    /**
     * The highest week a learner may open. Week 1 is open without an
     * enrollment, so a course can be started at all. After that, the later of
     * the week the calendar has reached and the week the learner has been moved
     * on to by hand (`current_week`, as the source site's plans did).
     */
    public function openWeek(?Enrollment $enrollment, ?CarbonInterface $now = null): int
    {
        if (! $enrollment instanceof Enrollment || $enrollment->started_at === null) {
            return max(1, (int) ($enrollment->current_week ?? 1));
        }

        $now ??= now();
        $elapsedDays = (int) floor($enrollment->started_at->diffInDays($now, false));
        $byCalendar = $elapsedDays < 0 ? 1 : intdiv($elapsedDays, 7) + 1;

        return max(1, $byCalendar, (int) $enrollment->current_week);
    }

    /**
     * When a scheduled week opens, or null when there is nothing to wait for.
     */
    public function weekOpensAt(?Enrollment $enrollment, ?int $week): ?CarbonInterface
    {
        if ($week === null || $week <= 1 || ! $enrollment instanceof Enrollment || $enrollment->started_at === null) {
            return null;
        }

        return $enrollment->started_at->copy()->addDays(($week - 1) * 7);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lessons
     * @param  array<string, array<string, mixed>>  $progressMap
     * @return array<string, bool>
     */
    protected function baseLockMap(Collection $lessons, array $progressMap, string $sequencingMode): array
    {
        if ($sequencingMode === 'lesson') {
            $previousCompleted = true;
            $lockMap = [];

            foreach ($lessons as $lesson) {
                $lockMap[$lesson['slug']] = ! $previousCompleted;

                if (($progressMap[$lesson['slug']]['status'] ?? null) !== LessonStatus::Completed->value) {
                    $previousCompleted = false;
                }
            }

            return $lockMap;
        }

        if ($sequencingMode === 'section') {
            $lockMap = [];
            $previousSectionsCompleted = true;

            foreach ($lessons->groupBy('section_key') as $sectionLessons) {
                $sectionCompleted = collect($sectionLessons)->every(
                    fn (array $lesson): bool => ($progressMap[$lesson['slug']]['status'] ?? null) === LessonStatus::Completed->value
                );

                foreach ($sectionLessons as $lesson) {
                    $lockMap[$lesson['slug']] = ! $previousSectionsCompleted;
                }

                if (! $sectionCompleted) {
                    $previousSectionsCompleted = false;
                }
            }

            return $lockMap;
        }

        return $lessons->mapWithKeys(fn (array $lesson): array => [$lesson['slug'] => false])->all();
    }

    /**
     * @param  array<string, mixed>  $milestone
     * @param  Collection<int, array<string, mixed>>  $lessons
     */
    protected function milestonePrerequisitesMet(array $milestone, Collection $lessons, callable $isCompleted): bool
    {
        $prerequisites = $milestone['prerequisite_slugs'] ?? [];

        if ($prerequisites !== []) {
            foreach ($prerequisites as $slug) {
                if (! $isCompleted((string) $slug)) {
                    return false;
                }
            }

            return true;
        }

        $phaseKey = $milestone['phase_key'] ?? null;
        if ($phaseKey === null || $phaseKey === '') {
            return false;
        }

        $siblings = $lessons->filter(fn (array $lesson): bool => ($lesson['phase_key'] ?? null) === $phaseKey
            && ($lesson['item_type'] ?? null) !== 'milestone'
            && $lesson['slug'] !== $milestone['slug']);

        return $siblings->isNotEmpty() && $siblings->every(fn (array $lesson): bool => $isCompleted($lesson['slug']));
    }

    /**
     * @param  array<string, array<string, mixed>>  $progressMap
     * @return callable(string): bool
     */
    protected function completedCheck(array $progressMap): callable
    {
        return fn (string $slug): bool => ($progressMap[$slug]['status'] ?? null) === LessonStatus::Completed->value;
    }
}
