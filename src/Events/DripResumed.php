<?php

namespace Goldnead\Courses\Events;

use Goldnead\Courses\Support\CourseBrand;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The drip clock runs again. `$pausedSeconds` is how long this pause lasted;
 * relative release dates move by that much. `$reason`: `payment_recovered`
 * or `manual`.
 */
class DripResumed
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
        public readonly int $pausedSeconds,
        public readonly string $reason,
        ?int $brandId = null,
    ) {
        $this->brandId = $brandId ?? CourseBrand::current();
    }
}
