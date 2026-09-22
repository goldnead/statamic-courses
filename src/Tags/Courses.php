<?php

namespace Goldnead\Courses\Tags;

use Goldnead\Courses\CourseProgress;
use Statamic\Facades\User;
use Statamic\Tags\Concerns\RendersForms;
use Statamic\Tags\Tags;

/**
 * Antlers access to courses, always for the signed-in learner.
 *
 *   {{ courses }}                                   every course, with has_access and progress
 *   {{ courses only="accessible" }}                 only the ones the learner may open
 *   {{ courses:progress course="cvt-101" }}         status, percent, completed/total, continue_lesson
 *   {{ courses:lessons course="cvt-101" }}          lessons with is_locked, is_completed, lock_reason, status
 *   {{ courses:continue course="cvt-101" }}         the next open, unfinished lesson
 *   {{ courses:form course="…" lesson="…" do="complete" }}…{{ /courses:form }}
 *
 * A guest sees courses and lessons without progress; `has_access` is false.
 * `courses:progress`, `lessons` and `continue` render nothing for a learner
 * without access to the course: this tag does not leak a paid outline.
 */
class Courses extends Tags
{
    use RendersForms;

    protected static $handle = 'courses';

    /** The form actions the POST route understands. */
    public const FORM_ACTIONS = ['complete', 'incomplete', 'acknowledge', 'progress'];

    /**
     * @return list<array<string, mixed>>|string
     */
    public function index(): array|string
    {
        $user = User::current();
        $onlyAccessible = $this->params->get('only') === 'accessible';
        $progress = $this->manager();

        $courses = collect($progress->courses())
            ->map(function (array $course) use ($user, $progress): array {
                $hasAccess = $user !== null && $progress->canAccess($user, $course['slug']);

                return [
                    ...$course,
                    'has_access' => $hasAccess,
                    'progress' => $hasAccess ? $progress->summary($user, $course['slug']) : null,
                ];
            })
            ->filter(fn (array $course): bool => ! $onlyAccessible || $course['has_access'])
            ->values()
            ->all();

        return $courses === [] ? '' : $courses;
    }

    /**
     * Nothing to show renders as an empty string, never as an empty array:
     * Antlers renders a pair's content once for `[]`, which would print a
     * lesson row with blank fields to a learner without access.
     *
     * @return array<string, mixed>|string
     */
    public function progress(): array|string
    {
        return $this->forLearner(fn ($user, string $slug) => $this->manager()->summary($user, $slug)) ?? '';
    }

    /**
     * @return list<array<string, mixed>>|string
     */
    public function lessons(): array|string
    {
        $lessons = $this->forLearner(fn ($user, string $slug) => $this->manager()->lessons($user, $slug)) ?? [];

        if ($lessons === []) {
            return '';
        }

        return array_map(fn (array $lesson): array => [
            ...$lesson,
            'status' => $lesson['progress']['status'],
            'completion_percent' => $lesson['progress']['completion_percent'],
        ], $lessons);
    }

    /**
     * @return array<string, mixed>|string
     */
    public function continue(): array|string
    {
        $summary = $this->forLearner(fn ($user, string $slug) => $this->manager()->summary($user, $slug));

        return $summary['continue_lesson'] ?? '';
    }

    /**
     * A form posting to the progress route. Renders nothing when the route is
     * switched off, so a template cannot point learners at a 404.
     */
    public function form(): string
    {
        if (! config('courses.routes.enabled', true)) {
            return '';
        }

        $action = $this->params->get('do', 'complete');

        if (! in_array($action, self::FORM_ACTIONS, true)) {
            $action = 'complete';
        }

        $html = $this->formOpen(route('statamic.courses.progress'), 'POST', ['course', 'lesson', 'do', 'redirect']);

        foreach ([
            'course' => $this->params->get('course'),
            'lesson' => $this->params->get('lesson'),
            'action' => $action,
            '_redirect' => $this->params->get('redirect'),
        ] as $name => $value) {
            if ($value !== null && $value !== '') {
                $html .= sprintf('<input type="hidden" name="%s" value="%s" />', $name, e((string) $value));
            }
        }

        return $html.$this->parse().$this->formClose();
    }

    /**
     * Runs $callback for the signed-in learner, or answers null for a guest,
     * a missing course or a learner without access.
     *
     * @template T
     *
     * @param  callable(mixed, string): T  $callback
     * @return T|null
     */
    protected function forLearner(callable $callback): mixed
    {
        $user = User::current();
        $slug = (string) $this->params->get('course', '');

        if ($user === null || $slug === '' || ! $this->manager()->canAccess($user, $slug)) {
            return null;
        }

        return $callback($user, $slug);
    }

    protected function manager(): CourseProgress
    {
        return app(CourseProgress::class);
    }
}
