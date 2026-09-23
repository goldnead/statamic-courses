<?php

/*
 * Stand-in for statamic-assessments' AssessmentCompleted: the response it
 * carries is read for assessment->handle, email, score, result_key and id,
 * the columns of the real Response model.
 */

namespace Goldnead\Assessments\Events;

if (! class_exists(AssessmentCompleted::class)) {
    class AssessmentCompleted
    {
        public function __construct(public object $response) {}
    }
}
