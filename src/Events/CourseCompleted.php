<?php

namespace Goldnead\Courses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Every lesson of a course is completed, milestones included.
 *
 * Fired by the write that completed the last lesson. The certificates addon
 * (planned) is the obvious listener.
 */
class CourseCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $courseId,
        public readonly string $courseSlug,
    ) {}
}
