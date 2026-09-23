<?php

namespace Goldnead\Courses\Integrations;

use Goldnead\Courses\CourseProgress;
use Goldnead\Courses\Events\QuizFailed;
use Goldnead\Courses\Events\QuizPassed;
use Goldnead\Courses\Support\CourseBrand;
use Goldnead\Courses\Support\LearnerId;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Facades\User;
use Throwable;

/**
 * A questionnaire from statamic-assessments as a lesson's quiz (K4).
 *
 * A lesson names an assessment by handle (`assessment`) and, optionally, what
 * counts as passing: a minimum score (`pass_score`), a set of result levels
 * (`pass_levels`), or both, and then both must hold. Neither: any submission
 * passes.
 *
 * On `AssessmentCompleted` the learner is the signed-in user who submitted;
 * a submission without a session counts for no lesson. Every open lesson of every course they may open that
 * embeds this assessment is then updated: passed completes the lesson through
 * completeLesson() with source `assessment`, which opens whatever waited on it;
 * not passed records the attempt and leaves the lesson open.
 *
 * Registered only when statamic-assessments is installed.
 */
class AssessmentsBridge
{
    public const COMPLETED = 'Goldnead\\Assessments\\Events\\AssessmentCompleted';

    public function __construct(protected CourseProgress $courses) {}

    public static function available(): bool
    {
        return class_exists(self::COMPLETED);
    }

    public function completed(object $event): void
    {
        $response = $event->response ?? null;

        if (! is_object($response)) {
            return;
        }

        $handle = (string) ($response->assessment->handle ?? '');
        $learner = $this->learnerFor($response);

        if ($handle === '' || $learner === null) {
            return;
        }

        $result = [
            'score' => (int) ($response->score ?? 0),
            'result_key' => is_string($response->result_key ?? null) ? $response->result_key : null,
            'response_id' => is_numeric($response->id ?? null) ? (int) $response->id : null,
        ];

        foreach ($this->courses->courses() as $course) {
            try {
                $this->applyToCourse($learner, $course, $handle, $result);
            } catch (Throwable $e) {
                Log::error('statamic-courses: an assessment result could not be applied to a course.', [
                    'course' => $course['slug'],
                    'assessment' => $handle,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $lesson
     */
    public static function passes(array $lesson, int $score, ?string $resultKey): bool
    {
        $minimum = $lesson['pass_score'] ?? null;
        $levels = $lesson['pass_levels'] ?? [];

        if ($minimum !== null && $score < (int) $minimum) {
            return false;
        }

        if ($levels !== [] && ! in_array($resultKey, $levels, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $course
     * @param  array{score: int, result_key: string|null, response_id: int|null}  $result
     */
    protected function applyToCourse(StatamicUser $learner, array $course, string $handle, array $result): void
    {
        // Brand-neutral: the questionnaire's page runs in the questionnaire's
        // brand, and the course grant may sit in another. Which brand a grant
        // was sold under does not decide whether its holder passed a quiz.
        if (! $this->brandNeutral(fn (): bool => $this->courses->canAccess($learner, $course['slug']))) {
            Log::debug('statamic-courses: an assessment result for a course the learner cannot open; not applied.', [
                'course' => $course['slug'],
                'assessment' => $handle,
            ]);

            return;
        }

        $this->brandNeutral(fn () => $this->applyToLessons($learner, $course, $handle, $result));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function brandNeutral(callable $callback): mixed
    {
        if (app()->bound('brand-context') && method_exists(app('brand-context'), 'withoutBrandScope')) {
            return app('brand-context')->withoutBrandScope($callback);
        }

        return $callback();
    }

    /**
     * @param  array<string, mixed>  $course
     * @param  array{score: int, result_key: string|null, response_id: int|null}  $result
     */
    protected function applyToLessons(StatamicUser $learner, array $course, string $handle, array $result): void
    {

        foreach ($this->courses->lessons($learner, $course['slug']) ?? [] as $lesson) {
            if (($lesson['assessment'] ?? null) !== $handle || $lesson['is_locked']) {
                continue;
            }

            $previous = is_array($lesson['progress']['item_payload'] ?? null) ? $lesson['progress']['item_payload'] : [];
            $payload = [
                'assessment' => $handle,
                'score' => $result['score'],
                'result_key' => $result['result_key'],
                'response_id' => $result['response_id'],
                'attempts' => (int) ($previous['attempts'] ?? 0) + 1,
            ];

            $passed = self::passes($lesson, $result['score'], $result['result_key']);
            $args = [LearnerId::of($learner), $course['id'], $course['slug'], $lesson['slug'], $handle, $result['score'], $result['result_key'], $result['response_id'], CourseBrand::forEvent($course)];

            if ($passed) {
                $this->courses->completeLesson($learner, $course['slug'], $lesson['slug'], 'assessment', [...$payload, 'passed' => true]);

                // Once per lesson: a retake of a passed quiz is not a new pass.
                if (! $lesson['is_completed']) {
                    QuizPassed::dispatch(...$args);
                }

                continue;
            }

            $this->courses->updateLessonItem($learner, $course['slug'], $lesson['slug'], [...$payload, 'passed' => (bool) ($previous['passed'] ?? false)]);
            QuizFailed::dispatch(...$args);
        }
    }

    /**
     * Only the signed-in user who submitted. Not the user behind the email
     * on the response: the questionnaire is a public form, and anybody could
     * type somebody else's address into it and pass a lesson in their name.
     */
    protected function learnerFor(object $response): ?StatamicUser
    {
        $current = User::current();

        return $current instanceof StatamicUser ? $current : null;
    }
}
