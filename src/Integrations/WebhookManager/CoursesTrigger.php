<?php

namespace Goldnead\Courses\Integrations\WebhookManager;

use Goldnead\WebhookManager\Contracts\TriggerInterface;
use Goldnead\WebhookManager\ValueObjects\TriggerEvent;

/**
 * One course event as a webhook-manager trigger.
 *
 * Implements the webhook manager's interface, so this class must never be
 * loaded on a site without that addon: {@see WebhookManagerBridge} creates it
 * only after checking the interface by name.
 */
class CoursesTrigger implements TriggerInterface
{
    public function __construct(
        private readonly string $handle,
        private readonly string $labelKey,
    ) {}

    public function handle(): string
    {
        return $this->handle;
    }

    /** Translated when asked, so the CP shows it in the reader's language. */
    public function label(): string
    {
        return (string) __($this->labelKey);
    }

    public function sourceType(): string
    {
        return 'courses';
    }

    /**
     * @param  mixed  $source  The course event, or an already built payload (replays, the CP simulator).
     */
    public function build(mixed $source, array $context = []): TriggerEvent
    {
        $payload = is_array($source) ? $source : WebhookPayload::for($this->handle, $source);

        return new TriggerEvent(
            triggerHandle: $this->handle,
            sourceType: $this->sourceType(),
            sourceReference: self::reference($payload),
            payload: $payload,
            site: null,
            locale: null,
            isReplay: (bool) ($context['replay'] ?? false),
            eventAt: new \DateTimeImmutable,
        );
    }

    /**
     * The course entry id: what the delivery log files a delivery under
     * (subject `courses` + id). Not course and learner together: the log's
     * subject columns hold 64 characters, two UUIDs do not fit, and the
     * webhook manager leaves a subject that does not fit empty.
     *
     * @param  array<string, mixed>  $payload
     */
    protected static function reference(array $payload): ?string
    {
        $course = $payload['course']['id'] ?? null;

        return is_string($course) && $course !== '' ? $course : null;
    }
}
