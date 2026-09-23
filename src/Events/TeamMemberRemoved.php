<?php

namespace Goldnead\Courses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A buyer took somebody off their team; the seat is free again.
 */
class TeamMemberRemoved
{
    use Dispatchable;

    public function __construct(
        public readonly string $ownerId,
        public readonly string $courseId,
        public readonly string $courseSlug,
        public readonly string $email,
        /** The purchase the seat belonged to: the course's product or a bundle. */
        public readonly string $product = '',
    ) {}
}
