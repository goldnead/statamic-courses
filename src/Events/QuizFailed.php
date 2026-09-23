<?php

namespace Goldnead\Courses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A learner submitted the assessment a lesson embeds and did not pass. The
 * attempt is recorded on the lesson (item_payload.attempts); the lesson
 * stays open for another try.
 */
class QuizFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $courseId,
        public readonly string $courseSlug,
        public readonly string $lessonSlug,
        public readonly string $assessment,
        public readonly int $score,
        public readonly ?string $resultKey,
        public readonly ?int $responseId,
    ) {}
}
