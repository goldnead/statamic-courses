<?php

namespace Goldnead\Courses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The drip clock stopped for a learner. `$reason`: `payment_failed` from the
 * payments bridge, `manual` from a direct call.
 */
class DripPaused
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $courseId,
        public readonly string $courseSlug,
        public readonly string $reason,
    ) {}
}
