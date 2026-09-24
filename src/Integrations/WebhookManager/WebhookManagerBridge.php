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
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Optional coupling to goldnead/statamic-webhook-manager: every course event
 * becomes a trigger an outbound webhook can listen to.
 *
 * Nothing of the webhook manager is touched before its classes are checked by
 * name. {@see CoursesTrigger} implements its interface, and loading that
 * class on a site without the addon is a fatal error at boot (the 0.2.0
 * private-media crash), so it is only ever created after the check.
 *
 * Booted from an `app->booted()` callback with a retry at the end of that
 * queue: whether the webhook manager has bound its service when this runs
 * depends on package order. Idempotent, so the retry costs nothing.
 */
class WebhookManagerBridge
{
    public const FACADE = 'Goldnead\\WebhookManager\\Facades\\WebhookManager';

    public const TRIGGER_INTERFACE = 'Goldnead\\WebhookManager\\Contracts\\TriggerInterface';

    /**
     * Event class => trigger handle, named as the automations triggers are.
     *
     * @var array<class-string, string>
     */
    public const TRIGGERS = [
        LearnerEnrolled::class => 'courses.learner_enrolled',
        LessonCompleted::class => 'courses.lesson_completed',
        LessonUnlocked::class => 'courses.lesson_unlocked',
        QuizPassed::class => 'courses.quiz_passed',
        QuizFailed::class => 'courses.quiz_failed',
        CourseCompleted::class => 'courses.course_completed',
        DripPaused::class => 'courses.drip_paused',
        DripResumed::class => 'courses.drip_resumed',
        CourseAccessSuspended::class => 'courses.access_suspended',
        CourseAccessRestored::class => 'courses.access_restored',
        TeamMemberAdded::class => 'courses.team_member_added',
        TeamMemberRemoved::class => 'courses.team_member_removed',
    ];

    protected bool $booted = false;

    public static function available(): bool
    {
        return (bool) config('courses.webhook_manager.enabled', true)
            && class_exists(self::FACADE)
            && interface_exists(self::TRIGGER_INTERFACE);
    }

    public function boot(Dispatcher $events): void
    {
        if ($this->booted || ! static::available()) {
            return;
        }

        // Bound once the webhook manager's own provider has booted. Not yet:
        // leave unbooted so the retry can still register.
        if (! app()->bound('webhook-manager')) {
            return;
        }

        $this->booted = true;

        foreach (static::TRIGGERS as $eventClass => $handle) {
            try {
                WebhookManager::registerTrigger(new CoursesTrigger($handle, 'courses::webhooks.'.self::key($handle)));
            } catch (\Throwable $e) {
                Log::warning('Courses → Webhook Manager: trigger ['.$handle.'] not registered: '.$e->getMessage());

                continue;
            }

            $events->listen($eventClass, function (object $event) use ($handle): void {
                $this->dispatch($handle, $event);
            });
        }
    }

    /** `courses.quiz_passed` → `quiz_passed`, the label's translation key. */
    public static function key(string $handle): string
    {
        return substr($handle, strlen('courses.'));
    }

    /**
     * In the course's brand: the webhook manager looks hooks up per brand, and
     * a console run or a payment webhook has none current, so the event's
     * brand is what decides whose hooks fire. Never breaks the course write.
     */
    protected function dispatch(string $handle, object $event): void
    {
        // After the write is committed: a moment inside a transaction that
        // is rolled back never happened, and one sent before the commit may
        // reach a receiver that then cannot find it. Outside a transaction
        // this runs at once.
        try {
            DB::afterCommit(fn () => $this->deliver($handle, $event));
        } catch (\Throwable $e) {
            Log::warning('Courses → Webhook Manager: ['.$handle.'] not dispatched: '.$e->getMessage());
        }
    }

    protected function deliver(string $handle, object $event): void
    {
        try {
            $trigger = WebhookManager::triggers()->get($handle);

            if ($trigger === null) {
                return;
            }

            $brandId = $event->brandId ?? null;

            // In the course's brand, or not at all when that brand cannot be
            // set: the only brand left would be whichever is current.
            WebhookPayload::runForBrand(
                is_int($brandId) ? $brandId : null,
                fn () => event(new TriggerDetected($trigger->build($event))),
                $handle,
            );
        } catch (\Throwable $e) {
            Log::warning('Courses → Webhook Manager: ['.$handle.'] not dispatched: '.$e->getMessage());
        }
    }
}
