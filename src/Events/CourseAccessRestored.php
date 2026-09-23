<?php

namespace Goldnead\Courses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A suspended course is open again for a learner.
 * `$reason`: `payment_recovered` or `manual`.
 */
class CourseAccessRestored
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $courseId,
        public readonly string $courseSlug,
        public readonly string $reason,
    ) {}
}
