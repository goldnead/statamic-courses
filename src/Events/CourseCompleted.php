<?php

namespace Goldnead\Courses\Events;

use Goldnead\Courses\Support\CourseBrand;
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
        ?int $brandId = null,
    ) {
        $this->brandId = $brandId ?? CourseBrand::current();
    }
}
