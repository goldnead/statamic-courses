<?php

namespace Goldnead\Courses\Integrations\WebhookManager;

use Goldnead\Courses\Events\CourseAccessRestored;
use Goldnead\Courses\Events\CourseAccessSuspended;
use Goldnead\Courses\Events\CourseCompleted;
use Goldnead\Courses\Events\DripPaused;
use Goldnead\Courses\Events\DripResumed;
use Goldnead\Courses\Events\LearnerEnrolled;
use Goldnead\Courses\Events\LessonCompleted;
use Goldnead\Courses\Events\LessonUnlocked;
use Goldnead\Courses\Events\QuizFailed;
use Goldnead\Courses\Events\QuizPassed;
use Goldnead\Courses\Events\TeamMemberAdded;
use Goldnead\Courses\Events\TeamMemberRemoved;
use Goldnead\Courses\Support\Learner;
use Statamic\Auth\User as StatamicUser;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Throwable;

/**
 * What a webhook receiver gets for a course event.
 *
 * Chosen field by field, never a model dump: a receiver (Zapier, n8n, a CRM)
 * needs to know who, which course, which lesson and what happened, and
 * nothing a lesson state row carries beyond that (watch positions, item
 * payloads with answers, internal ids of rows).
 *
 * Every payload has the same frame as its siblings in the suite:
 *
 *     event        the trigger handle, e.g. courses.quiz_passed
 *     occurred_at  ISO 8601 with offset
 *     brand        {id, handle} or null
 *
 * People are looked up as Statamic users: `{id, email, name}`. A user that
 * cannot be found (deleted, or an id from another system) is sent as `{id}`
 * alone rather than with invented or empty fields.
 *
 * Deliberately free of webhook-manager classes, so it can be read and tested
 * on a site without that addon.
 */
final class WebhookPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function for(string $handle, object $event): array
    {
        return [
            'event' => $handle,
            'occurred_at' => now()->toIso8601String(),
            'brand' => self::brand($event->brandId ?? null),
            ...self::body($event),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function body(object $event): array
    {
        return match (true) {
            $event instanceof LessonCompleted => [
                'learner' => self::person($event->state->user_id),
                'course' => self::course((string) $event->state->course_entry_id, (string) $event->state->course_slug),
                'lesson' => [
                    'id' => $event->state->lesson_entry_id,
                    'slug' => $event->state->lesson_slug,
                ],
                'source' => $event->source,
                'completed_at' => $event->state->completed_at?->toIso8601String(),
            ],
            $event instanceof LessonUnlocked => [
                'learner' => self::person($event->userId),
                'course' => self::course($event->courseId, $event->courseSlug),
                'lesson' => ['slug' => $event->lessonSlug],
                'source' => $event->source,
            ],
            $event instanceof QuizPassed, $event instanceof QuizFailed => [
                'learner' => self::person($event->userId),
                'course' => self::course($event->courseId, $event->courseSlug),
                'lesson' => ['slug' => $event->lessonSlug],
                'assessment' => $event->assessment,
                'score' => $event->score,
                'passed' => $event instanceof QuizPassed,
                'result_key' => $event->resultKey,
                'response_id' => $event->responseId,
            ],
            $event instanceof DripResumed => [
                'learner' => self::person($event->userId),
                'course' => self::course($event->courseId, $event->courseSlug),
                'reason' => $event->reason,
                'paused_seconds' => $event->pausedSeconds,
            ],
            $event instanceof DripPaused,
            $event instanceof CourseAccessSuspended,
            $event instanceof CourseAccessRestored => [
                'learner' => self::person($event->userId),
                'course' => self::course($event->courseId, $event->courseSlug),
                'reason' => $event->reason,
            ],
            $event instanceof LearnerEnrolled, $event instanceof CourseCompleted => [
                'learner' => self::person($event->userId),
                'course' => self::course($event->courseId, $event->courseSlug),
            ],
            $event instanceof TeamMemberAdded, $event instanceof TeamMemberRemoved => [
                'member' => self::member($event->email),
                'owner' => self::person($event->ownerId),
                'course' => self::course($event->courseId, $event->courseSlug),
                'product' => $event->product !== '' ? $event->product : null,
            ],
            default => [],
        };
    }

    /**
     * @return array{id: string, email?: string|null, name?: string|null}
     */
    public static function person(mixed $id): array
    {
        $id = (string) $id;
        $user = $id !== '' ? Learner::user($id) : null;

        return $user instanceof StatamicUser ? self::user($user) : ['id' => $id];
    }

    /**
     * A team member may not have an account yet; the address is who to invite.
     *
     * @return array{id?: string, email: string, name?: string|null}
     */
    protected static function member(string $email): array
    {
        try {
            $user = User::findByEmail($email);
        } catch (Throwable) {
            $user = null;
        }

        return $user instanceof StatamicUser ? self::user($user) : ['email' => $email];
    }

    /**
     * @return array{id: string, email: string|null, name: string|null}
     */
    protected static function user(StatamicUser $user): array
    {
        $name = $user->name();

        return [
            'id' => (string) $user->getAuthIdentifier(),
            'email' => $user->email(),
            'name' => is_string($name) && $name !== '' ? $name : null,
        ];
    }

    /**
     * @return array{id: string, slug: string, title: string|null}
     */
    protected static function course(string $id, string $slug): array
    {
        try {
            $entry = Entry::find($id);
            $title = $entry instanceof StatamicEntry ? $entry->get('title') : null;
        } catch (Throwable) {
            $title = null;
        }

        return [
            'id' => $id,
            'slug' => $slug,
            'title' => is_string($title) ? $title : null,
        ];
    }

    /**
     * @return array{id: int, handle: string|null}|null
     */
    public static function brand(mixed $brandId): ?array
    {
        if (! is_int($brandId)) {
            return null;
        }

        $model = 'Goldnead\\BrandContext\\Models\\Brand';
        $handle = null;

        if (class_exists($model)) {
            try {
                $handle = $model::query()->whereKey($brandId)->value('handle');
            } catch (Throwable) {
                $handle = null;
            }
        }

        return ['id' => $brandId, 'handle' => is_string($handle) ? $handle : null];
    }
}
