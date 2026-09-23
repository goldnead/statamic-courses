<?php

namespace Goldnead\Courses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A buyer put somebody on their team for a course. The member may not have
 * an account yet: `$email` is who to invite.
 */
class TeamMemberAdded
{
    use Dispatchable;

    public function __construct(
        public readonly string $ownerId,
        public readonly string $courseId,
        public readonly string $courseSlug,
        public readonly string $email,
        /** The purchase the seat belongs to: the course's product or a bundle. */
        public readonly string $product = '',
    ) {}
}
