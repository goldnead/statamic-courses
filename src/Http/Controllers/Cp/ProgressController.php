<?php

namespace Goldnead\Courses\Http\Controllers\Cp;

use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Models\LessonState;
use Goldnead\Courses\Support\ProgressReport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\CP\Column;
use Statamic\Facades\Collection;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Course Progress: one row per course, read-only.
 *
 * Client-mode listing: a site has a handful of courses, not thousands, so
 * the rows travel with the page and search and sort happen in the browser.
 */
class ProgressController extends CpController
{
    public function index(ProgressReport $report): Response
    {
        Gate::authorize('view course progress');

        // A fresh install whose migrations have not run gets a sentence, not a 500.
        if (! Schema::hasTable((new LessonState)->getTable()) || ! Schema::hasTable((new Enrollment)->getTable())) {
            Log::warning('statamic-courses: the course tables are missing; run php artisan migrate.');

            return Inertia::render('courses::Progress/Index', [
                'rows' => [],
                'initialColumns' => [],
                'setupRequired' => true,
            ]);
        }

        $rows = $report->overview();
        $handle = (string) config('courses.collections.courses', 'courses');
        $hasCollection = Collection::find($handle) !== null;

        return Inertia::render('courses::Progress/Index', [
            'rows' => $rows,
            'initialColumns' => collect($this->columns())->map->toArray()->all(),
            'hasLearners' => collect($rows)->sum('learners') > 0,
            // The install hint is for a site without the collection. With it,
            // the table shows, zeros and all.
            'hasCollection' => $hasCollection,
            // The CP user's language, set by core's Localize middleware. The
            // page formats dates in it instead of the browser's.
            'locale' => str_replace('_', '-', app()->getLocale()),
            'collectionUrl' => $hasCollection ? cp_route('collections.show', $handle) : null,
            'stuckHelp' => __('courses::cp.stuck_help', ['days' => $report->stuckAfterDays()]),
        ]);
    }

    /**
     * @return list<Column>
     */
    protected function columns(): array
    {
        return [
            Column::make('title')->label(__('courses::cp.col_title')),
            Column::make('learners')->label(__('courses::cp.col_learners'))->numeric(true),
            Column::make('in_progress')->label(__('courses::cp.col_in_progress'))->numeric(true),
            Column::make('completed')->label(__('courses::cp.col_completed'))->numeric(true),
            Column::make('completion_rate')->label(__('courses::cp.col_completion_rate'))->numeric(true),
            Column::make('stuck')->label(__('courses::cp.col_stuck'))->numeric(true),
            Column::make('last_activity_at')->label(__('courses::cp.col_last_activity')),
        ];
    }
}
