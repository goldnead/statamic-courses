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
use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Models\TeamMember;
use Goldnead\Courses\Support\Learner;
use Illuminate\Support\Facades\Log;
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
 *     event         the trigger handle, e.g. courses.quiz_passed
 *     event_id      sha1(handle|<type>:<id>|<the row's time>), stable per moment
 *     occurred_at   the moment's own time, ISO 8601 with offset
 *     brand         {id, handle} or null
 *     subject_type  course
 *     subject_id    the course entry id
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
        $body = self::body($event);
        $subject = $body['course']['id'] ?? null;
        $parts = self::momentParts($event);

        return [
            'event' => $handle,
            // The same moment always gets the same id, however often it is
            // sent. A receiver deduplicates on it; order is not guaranteed.
            'event_id' => self::eventId($handle, $parts),
            'occurred_at' => self::occurredAt($parts)->format(\DATE_ATOM),
            'brand' => self::brand($event->brandId ?? null),
            // Named outright, as the payments addon does: the course the
            // moment belongs to, so "deliveries for this object" finds it.
            'subject_type' => 'course',
            'subject_id' => $subject,
            ...$body,
        ];
    }

    /**
     * `sha1(handle|part|part…)`, dates as DATE_ATOM: the same recipe in every
     * addon of the suite.
     *
     * @param  list<mixed>  $parts
     */
    public static function eventId(string $handle, array $parts): string
    {
        return sha1(implode('|', array_map(
            fn ($part) => $part instanceof \DateTimeInterface ? $part->format(\DATE_ATOM) : (string) $part,
            [$handle, ...$parts],
        )));
    }

    /**
     * The first date among the parts, the moment's own time. The clock only
     * where no row records one.
     *
     * @param  list<mixed>  $parts
     */
    public static function occurredAt(array $parts): \DateTimeInterface
    {
        foreach ($parts as $part) {
            if ($part instanceof \DateTimeInterface) {
                return $part;
            }
        }

        return now();
    }

    /**
     * What separates this moment from every other moment of the same kind:
     * the row it concerns as `<type>:<id>`, then the time that row records
     * for it. Never the time of sending.
     *
     * A lesson unlock, a quiz attempt and a removed team seat leave no time
     * of their own; they are told apart by learner, lesson and response, or
     * owner and address.
     *
     * @return list<mixed>
     */
    public static function momentParts(object $event): array
    {
        if ($event instanceof LessonCompleted) {
            return ['lesson_state:'.$event->state->id, $event->state->completed_at ?? ''];
        }

        if ($event instanceof TeamMemberAdded) {
            $row = TeamMember::query()->where('owner_id', $event->ownerId)->where('email', $event->email)->first();

            return $row !== null
                ? ['team_member:'.$row->id, $row->created_at ?? '']
                : ['course:'.$event->courseId, 'owner:'.$event->ownerId, 'member:'.$event->email, 'added'];
        }

        if ($event instanceof TeamMemberRemoved) {
            return ['course:'.$event->courseId, 'owner:'.$event->ownerId, 'member:'.$event->email, 'removed'];
        }

        if ($event instanceof QuizPassed || $event instanceof QuizFailed) {
            return ['course:'.$event->courseId, 'user:'.$event->userId, 'lesson:'.$event->lessonSlug, 'response:'.$event->responseId];
        }

        if ($event instanceof LessonUnlocked) {
            return ['course:'.$event->courseId, 'user:'.$event->userId, 'lesson:'.$event->lessonSlug];
        }

        $enrollment = isset($event->userId, $event->courseId)
            ? Enrollment::query()->where('user_id', $event->userId)->where('course_entry_id', $event->courseId)->first()
            : null;

        if ($enrollment === null) {
            return ['course:'.($event->courseId ?? ''), 'user:'.($event->userId ?? ''), $event::class];
        }

        $row = 'enrollment:'.$enrollment->id;

        return match (true) {
            $event instanceof LearnerEnrolled => [$row, $enrollment->started_at ?? 'enrolled'],
            $event instanceof CourseCompleted => [$row, $enrollment->completed_at ?? 'completed'],
            $event instanceof DripPaused => [$row, $enrollment->drip_paused_at ?? 'drip_paused'],
            $event instanceof CourseAccessSuspended => [$row, $enrollment->access_suspended_at ?? 'suspended'],
            // The pause ended and its column was cleared: the write that
            // cleared it is the moment's time.
            $event instanceof DripResumed => [$row, $enrollment->updated_at ?? '', 'drip_resumed'],
            $event instanceof CourseAccessRestored => [$row, $enrollment->updated_at ?? '', 'restored'],
            default => [$row, $event::class],
        };
    }

    /**
     * Run the hand-over as the brand the moment names, or not at all.
     *
     * A brand that cannot be made current (deleted, a typo in a course's
     * `brand` field) is not replaced by whichever brand is current: its hooks
     * belong to another tenant. Logged, not delivered. No brand named, or no
     * brand-context installed: runs as it is. The same rule as the payments
     * addon's `WebhookPayload::runForBrand()`.
     *
     * @param  \Closure(): void  $callback
     */
    public static function runForBrand(?int $brand, \Closure $callback, string $handle): bool
    {
        if (! $brand || ! app()->bound('brand-context')) {
            $callback();

            return true;
        }

        $ran = false;

        try {
            app('brand-context')->runFor($brand, function () use ($callback, &$ran): void {
                $ran = true;
                $callback();
            });

            return true;
        } catch (Throwable $e) {
            if ($ran) {
                throw $e;
            }

            Log::warning('statamic-courses: the course names a brand that cannot be set; the webhook was not delivered rather than sent through another brand\'s hooks.', [
                'trigger' => $handle,
                'brand_id' => $brand,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
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
