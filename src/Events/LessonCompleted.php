<?php

namespace Goldnead\Courses\Events;

use Goldnead\Courses\Models\LessonState;
use Goldnead\Courses\Support\CourseBrand;
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

    /**
     * The course's brand on a multi-brand site, else the brand current when
     * it fired; null without statamic-brand-context. A listener started from
     * a webhook or the console runs in this brand.
     */
    public readonly ?int $brandId;

    public function __construct(
        public readonly LessonState $state,
        public readonly string $source,
        ?int $brandId = null,
    ) {
        $this->brandId = $brandId ?? CourseBrand::current();
    }
}
