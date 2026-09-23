<?php

namespace Goldnead\Courses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A learner passed the assessment a lesson embeds. Fired after the lesson
 * was completed (so after LessonCompleted and any LessonUnlocked it caused).
 */
class QuizPassed
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
