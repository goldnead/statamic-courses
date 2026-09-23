<?php

namespace Goldnead\Courses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A course was shut for a learner, whatever their entitlement says.
 * `$reason`: `payment_failed` or `manual`.
 */
class CourseAccessSuspended
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $courseId,
        public readonly string $courseSlug,
        public readonly string $reason,
    ) {}
}
