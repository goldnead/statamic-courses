<?php

namespace Goldnead\Courses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The drip clock runs again. `$pausedSeconds` is how long this pause lasted;
 * relative release dates move by that much. `$reason`: `payment_recovered`
 * or `manual`.
 */
class DripResumed
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $courseId,
        public readonly string $courseSlug,
        public readonly int $pausedSeconds,
        public readonly string $reason,
    ) {}
}
