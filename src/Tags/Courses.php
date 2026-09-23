<?php

namespace Goldnead\Courses\Tags;

use Goldnead\Courses\CourseProgress;
use Goldnead\Courses\Support\CourseRepository;
use Goldnead\Courses\Support\LessonBlocks;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\Entry;
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
 *   {{ courses:blocks }}                            the lesson's content blocks
 *   {{ courses:quiz }}…{{ /courses:quiz }}          the lesson's quiz and the learner's result
 *   {{ courses:team course="…" }}…{{ /courses:team }}  seats and members of the buyer's team
 *   {{ courses:team_form course="…" }}…{{ /courses:team_form }}
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
            // `skipped` for a lesson a test-out completed; progress:status
            // keeps `completed`, which is what the rollup counts.
            'status' => $lesson['is_skipped'] ? 'skipped' : $lesson['progress']['status'],
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
     * The lesson's content blocks (K1), for the lesson in context or the
     * entry id in `lesson`.
     *
     *   {{ courses:blocks }}                         the shipped partials, one per block
     *   {{ courses:blocks }}…{{ /courses:blocks }}   the blocks, to draw yourself (`type` + fields)
     *
     * A site overrides one partial by placing its own at
     * resources/views/vendor/courses/blocks/{type}.antlers.html.
     *
     * Renders nothing for a guest, a learner without access, and a lesson that
     * is locked or hidden for this learner. Super users see every lesson, so
     * the Control Panel's live preview works.
     *
     * @return list<array<string, mixed>>|string
     */
    public function blocks(): array|string
    {
        $readable = $this->readableLesson();

        if ($readable === null) {
            return '';
        }

        [$entry, $course, $user] = $readable;

        $blocks = app(LessonBlocks::class)->for($entry, $user, $course['product'] ?? null);

        if ($blocks === []) {
            return '';
        }

        if ($this->isPair) {
            return $blocks;
        }

        $html = '';

        foreach ($blocks as $block) {
            $view = 'courses::blocks.'.$block['type'];

            if (view()->exists($view)) {
                $html .= view($view, $block)->render();
            }
        }

        return '<div class="courses-blocks">'.$html.'</div>';
    }

    /**
     * The quiz of the lesson in context (K4): which assessment, where it
     * lives, and how the learner has done so far.
     *
     *   {{ courses:quiz }}
     *     {{ if passed }}Bestanden mit {{ score }} Punkten.{{ else }}<a href="{{ url }}">Zum Quiz</a>{{ /if }}
     *   {{ /courses:quiz }}
     *
     * Variables: `assessment`, `url` (the questionnaire's page, empty without
     * statamic-assessments), `passed`, `attempts`, `score`, `result_key`,
     * `pass_score`, `pass_levels`. Nothing for a lesson without a quiz, or one
     * the learner cannot open.
     *
     * @return array<string, mixed>|string
     */
    public function quiz(): array|string
    {
        $readable = $this->readableLesson();

        if ($readable === null) {
            return '';
        }

        [$entry, $course, $user] = $readable;
        $lesson = $this->manager()->lesson($user, $course['slug'], (string) $entry->slug());

        // A super user previewing a lesson its audience rule hides from them:
        // the quiz as configured, without a learner's result.
        if ($lesson === null && $user->isSuper()) {
            $raw = app(CourseRepository::class)->lessonsFor($course['id'])->firstWhere('slug', (string) $entry->slug());
            $lesson = is_array($raw) ? [...$raw, 'is_completed' => false, 'progress' => ['item_payload' => null]] : null;
        }

        if ($lesson === null || ($lesson['assessment'] ?? null) === null) {
            return '';
        }

        $payload = is_array($lesson['progress']['item_payload'] ?? null) ? $lesson['progress']['item_payload'] : [];

        return [
            'assessment' => $lesson['assessment'],
            'url' => Route::has('assessments.show') ? route('assessments.show', $lesson['assessment']) : '',
            'passed' => $lesson['is_completed'] && (bool) ($payload['passed'] ?? $lesson['is_completed']),
            'attempts' => (int) ($payload['attempts'] ?? 0),
            'score' => $payload['score'] ?? null,
            'result_key' => $payload['result_key'] ?? null,
            'pass_score' => $lesson['pass_score'],
            'pass_levels' => $lesson['pass_levels'],
        ];
    }

    /**
     * The signed-in buyer's team for a course (K6).
     *
     *   {{ courses:team course="chorleitung" }}
     *     {{ left }} von {{ seats }} Plätzen frei
     *     {{ members }}{{ email }}{{ /members }}
     *   {{ /courses:team }}
     *
     * Nothing for a guest, a course without seats, or somebody who holds the
     * course only through somebody else's team.
     *
     * @return array<string, mixed>|string
     */
    public function team(): array|string
    {
        $user = User::current();
        $slug = (string) $this->params->get('course', '');
        $team = $user === null || $slug === '' ? null : $this->manager()->team($user, $slug);

        return $team === null || $team['seats'] === 0 ? '' : [...$team, 'course' => $slug];
    }

    /**
     * A form that adds (`do="add"`, the default) or removes (`do="remove"`)
     * a team member by `email`. Posts to /!/courses/team; nothing when the
     * front-end routes are off.
     *
     *   {{ courses:team_form course="chorleitung" }}
     *     <input type="email" name="email" required> <button>Hinzufügen</button>
     *   {{ /courses:team_form }}
     */
    public function teamForm(): string
    {
        if (! config('courses.routes.enabled', true)) {
            return '';
        }

        $action = $this->params->get('do', 'add') === 'remove' ? 'remove' : 'add';

        $html = $this->formOpen(route('statamic.courses.team'), 'POST', ['course', 'do', 'redirect', 'email']);

        foreach ([
            'course' => $this->params->get('course'),
            'action' => $action,
            'email' => $this->params->get('email'),
            '_redirect' => $this->params->get('redirect'),
        ] as $name => $value) {
            if ($value !== null && $value !== '') {
                $html .= sprintf('<input type="hidden" name="%s" value="%s" />', $name, e((string) $value));
            }
        }

        return $html.$this->parse().$this->formClose();
    }

    /**
     * The lesson in context (or `lesson="<entry id>"`), its course, and the
     * learner, when the learner may read it: signed in, with access to the
     * course, and the lesson neither locked nor hidden for them. Super users
     * may read everything.
     *
     * @return array{0: \Statamic\Entries\Entry, 1: array<string, mixed>, 2: mixed}|null
     */
    protected function readableLesson(): ?array
    {
        $id = $this->params->get('lesson') ?? $this->context->value('id');
        $entry = is_string($id) && $id !== '' ? Entry::find($id) : null;

        if (! $entry instanceof \Statamic\Entries\Entry
            || $entry->collectionHandle() !== (string) config('courses.collections.lessons', 'course_lessons')) {
            return null;
        }

        $courseId = $entry->value('course');
        $courseId = is_array($courseId) ? ($courseId[0] ?? null) : $courseId;
        $courseEntry = is_string($courseId) && $courseId !== '' ? Entry::find($courseId) : null;
        $course = $courseEntry instanceof \Statamic\Entries\Entry ? $this->manager()->course((string) $courseEntry->slug()) : null;
        $user = User::current();

        if ($course === null || $user === null) {
            return null;
        }

        if ($user->isSuper()) {
            return [$entry, $course, $user];
        }

        if (! $this->manager()->canAccess($user, $course['slug'])) {
            return null;
        }

        $lesson = $this->manager()->lesson($user, $course['slug'], (string) $entry->slug());

        return $lesson === null || $lesson['is_locked'] ? null : [$entry, $course, $user];
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
