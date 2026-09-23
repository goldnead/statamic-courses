<?php

namespace Goldnead\Courses\Events;

use Goldnead\Courses\Support\CourseBrand;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A learner was enrolled in a course for the first time. Fired once per
 * learner and course, by the call that created the enrollment row.
 */
class LearnerEnrolled
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
