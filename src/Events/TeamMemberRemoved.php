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
    ) {}
}
