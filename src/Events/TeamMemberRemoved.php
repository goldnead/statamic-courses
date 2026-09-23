<?php

namespace Goldnead\Courses\Events;

use Goldnead\Courses\Support\CourseBrand;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A buyer took somebody off their team; the seat is free again.
 */
class TeamMemberRemoved
{
    use Dispatchable;

    /**
     * The course's brand on a multi-brand site, else the brand current when
     * it fired; null without statamic-brand-context. A listener started from
     * a webhook or the console runs in this brand.
     */
    public readonly ?int $brandId;

    public function __construct(
        public readonly string $ownerId,
        public readonly string $courseId,
        public readonly string $courseSlug,
        public readonly string $email,
        /** The purchase the seat belonged to: the course's product or a bundle. */
        public readonly string $product = '',
        ?int $brandId = null,
    ) {
        $this->brandId = $brandId ?? CourseBrand::current();
    }
}
