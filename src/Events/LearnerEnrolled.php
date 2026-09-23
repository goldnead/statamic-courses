<?php

namespace Goldnead\Courses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A learner was enrolled in a course for the first time. Fired once per
 * learner and course, by the call that created the enrollment row.
 */
class LearnerEnrolled
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $courseId,
        public readonly string $courseSlug,
    ) {}
}
