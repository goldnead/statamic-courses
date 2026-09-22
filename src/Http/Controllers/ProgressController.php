<?php

namespace Goldnead\Courses\Http\Controllers;

use Goldnead\Courses\CourseProgress;
use Goldnead\Courses\Tags\Courses as CoursesTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Statamic\Facades\User;

/**
 * POST /!/courses/progress: a learner marks a lesson from a template form or
 * a script.
 *
 * Four gates, in order: the route switch, a signed-in user, access to the
 * course, and the lesson's own lock and type rules (a locked lesson, or a quiz
 * ticked by hand, is refused with 422). CSRF comes from the `web` group every
 * Statamic action route runs in.
 *
 * Answers JSON to a request that asks for it, otherwise redirects back, or to
 * `_redirect` when that is a path on this site.
 */
class ProgressController extends Controller
{
    public function __invoke(Request $request, CourseProgress $courses): JsonResponse|RedirectResponse
    {
        abort_unless(config('courses.routes.enabled', true), 404);

        $user = User::current();

        if ($user === null) {
            return $this->fail($request, 401, 'unauthenticated');
        }

        $data = $request->validate([
            'course' => ['required', 'string', 'max:191'],
            'lesson' => ['required', 'string', 'max:191'],
            'action' => ['required', Rule::in(CoursesTag::FORM_ACTIONS)],
            'watched_seconds' => ['nullable', 'integer', 'min:0'],
            'resume_seconds' => ['nullable', 'integer', 'min:0'],
            'video_duration_seconds' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($courses->course($data['course']) === null) {
            return $this->fail($request, 404, 'course_not_found');
        }

        if (! $courses->canAccess($user, $data['course'])) {
            return $this->fail($request, 403, 'no_access');
        }

        $lesson = match ($data['action']) {
            'complete' => $courses->setLessonCompletion($user, $data['course'], $data['lesson'], true),
            'incomplete' => $courses->setLessonCompletion($user, $data['course'], $data['lesson'], false),
            'acknowledge' => $courses->acknowledgeLesson($user, $data['course'], $data['lesson']),
            'progress' => $courses->updateLessonProgress($user, $data['course'], $data['lesson'], array_filter([
                'watched_seconds' => $data['watched_seconds'] ?? null,
                'resume_seconds' => $data['resume_seconds'] ?? null,
                'video_duration_seconds' => $data['video_duration_seconds'] ?? null,
            ], fn ($value) => $value !== null)),
            default => null,
        };

        if ($lesson === null) {
            return $this->fail($request, 422, 'refused');
        }

        if ($request->expectsJson()) {
            return response()->json(['lesson' => $lesson]);
        }

        return $this->back($request)->with('courses.status', 'saved');
    }

    protected function fail(Request $request, int $status, string $reason): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => $reason], $status);
        }

        return $this->back($request)->withErrors(['courses' => $reason]);
    }

    /**
     * Back, or to `_redirect` when it is a path on this site. Never to another
     * host: this is a form any page can post to.
     */
    protected function back(Request $request): RedirectResponse
    {
        $target = (string) $request->input('_redirect', '');

        if (str_starts_with($target, '/') && ! str_starts_with($target, '//') && ! str_contains($target, '\\')) {
            return redirect($target);
        }

        return redirect()->back();
    }
}
