<?php

namespace Goldnead\Courses\Events;

use Goldnead\Courses\Models\LessonState;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A lesson went from not completed to completed, by hand or by watching.
 *
 * Fired once per transition, not on every save. The source site hung its
 * community milestone posts off this moment; here that is a listener's job.
 */
class LessonCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly LessonState $state,
        public readonly string $source,
    ) {}
}
