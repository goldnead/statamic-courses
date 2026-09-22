<?php

namespace Goldnead\Courses\Support;

use Carbon\CarbonInterface;
use Goldnead\Courses\CourseProgress;
use Goldnead\Courses\Enums\LessonStatus;
use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Models\LessonState;
use Illuminate\Support\Carbon;
use Statamic\Facades\Entry;

/**
 * One row per course for the "Course Progress" screen.
 *
 * The generic half of adriangoldner.com's CourseAnalyticsService
 * (courseOverviewRow). The source counted the learners entitled to the
 * course; entitlements has no "everybody holding product X" query an addon
 * could rely on, so here a learner is anybody who enrolled or touched a
 * lesson. The completion rate is therefore "of those who started", not "of
 * those who bought", which is what the column says.
 *
 * `stuck` is new: started, not finished, and no activity for
 * `courses.cp.stuck_after_days` days.
 */
class ProgressReport
{
    public function __construct(protected CourseProgress $progress) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function overview(?CarbonInterface $now = null): array
    {
        $now ??= Carbon::now();
        $stuckBefore = $now->copy()->subDays($this->stuckAfterDays());

        return collect($this->progress->courses())
            ->map(fn (array $course): array => $this->row($course, $stuckBefore))
            ->values()
            ->all();
    }

    protected function editUrl(string $entryId): ?string
    {
        $entry = Entry::find($entryId);

        return $entry instanceof \Statamic\Entries\Entry ? $entry->editUrl() : null;
    }

    public function stuckAfterDays(): int
    {
        return max(1, (int) config('courses.cp.stuck_after_days', 14));
    }

    /**
     * @param  array<string, mixed>  $course
     * @return array<string, mixed>
     */
    protected function row(array $course, CarbonInterface $stuckBefore): array
    {
        $learners = LessonState::query()->where('course_entry_id', $course['id'])->distinct()->pluck('user_id')
            ->merge(Enrollment::query()->where('course_entry_id', $course['id'])->pluck('user_id'))
            ->unique()
            ->values();

        $summaries = $this->progress->summaries($course['slug'], $learners);

        $completed = 0;
        $inProgress = 0;
        $stuck = 0;
        $lastActivity = null;

        foreach ($summaries as $summary) {
            $at = $summary['last_activity_at'] ? Carbon::parse($summary['last_activity_at']) : null;

            if ($at !== null && ($lastActivity === null || $at->greaterThan($lastActivity))) {
                $lastActivity = $at;
            }

            if ($summary['status'] === LessonStatus::Completed->value) {
                $completed++;
            } elseif ($summary['status'] === LessonStatus::InProgress->value) {
                $inProgress++;

                if ($at === null || $at->lessThan($stuckBefore)) {
                    $stuck++;
                }
            }
        }

        $total = count($summaries);

        return [
            'id' => $course['id'],
            'slug' => $course['slug'],
            'title' => $course['title'],
            'edit_url' => $this->editUrl($course['id']),
            'learners' => $total,
            'in_progress' => $inProgress,
            'completed' => $completed,
            'completion_rate' => $total > 0 ? (int) floor(($completed / $total) * 100) : 0,
            'stuck' => $stuck,
            'last_activity_at' => $lastActivity?->toIso8601String(),
        ];
    }
}
