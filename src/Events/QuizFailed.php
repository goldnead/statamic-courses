<?php

namespace Goldnead\Courses\Events;

use Goldnead\Courses\Support\CourseBrand;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A learner submitted the assessment a lesson embeds and did not pass. The
 * attempt is recorded on the lesson (item_payload.attempts); the lesson
 * stays open for another try.
 */
class QuizFailed
{
    use Dispatchable;

    /**
     * The course's brand on a multi-brand site, else the brand current when
     * it fired; null without statamic-brand-context. A listener started from
     * a webhook or the console runs in this brand.
     */
    public readonly ?int $brandId;

    public function __construct(
        public readonly string $userId,
        public readonly string $courseId,
        public readonly string $courseSlug,
        public readonly string $lessonSlug,
        public readonly string $assessment,
        public readonly int $score,
        public readonly ?string $resultKey,
        public readonly ?int $responseId,
        ?int $brandId = null,
    ) {
        $this->brandId = $brandId ?? CourseBrand::current();
    }
}
