<?php

namespace Goldnead\Courses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A lesson went from locked to open for a learner, because of a write:
 * a completed lesson, a passed quiz, a payment, a resumed drip.
 *
 * `$source` names that write: `manual`, `auto`, `assessment`, `billing`,
 * `drip_resumed`, `access_restored` or whatever source the caller passed to
 * completeLesson(). A lesson the calendar opens (drip by days or date) has no
 * write behind it and is not announced.
 */
class LessonUnlocked
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $courseId,
        public readonly string $courseSlug,
        public readonly string $lessonSlug,
        public readonly string $source,
    ) {}
}
