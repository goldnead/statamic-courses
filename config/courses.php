<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | The two collections a course is made of. `php artisan courses:install`
    | creates them with the shipped blueprints under these handles. Change the
    | handles here before installing if a site already uses the names.
    |
    */

    'collections' => [
        'courses' => 'courses',
        'lessons' => 'course_lessons',
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic completion
    |--------------------------------------------------------------------------
    |
    | A video lesson counts as completed once this share of it has been watched,
    | in percent. 90 is the value the source site ran with: late enough that a
    | skipped lesson does not count, early enough that nobody has to sit through
    | the credits.
    |
    */

    'auto_completion_threshold' => 90,

    /*
    |--------------------------------------------------------------------------
    | Progress events
    |--------------------------------------------------------------------------
    |
    | Every start, quarter mark and completion is written to the
    | courses_lesson_events table. Turn it off if nothing reads that table.
    |
    */

    'record_events' => true,

    /*
    |--------------------------------------------------------------------------
    | Entitlements
    |--------------------------------------------------------------------------
    |
    | The subject type a learner is looked up under when the learner is not an
    | Eloquent model (a flat-file Statamic user, a bare id). It must match the
    | type the grants were written with, or every course stays closed.
    |
    | Null picks it for you: the morph class of the auth user model when
    | Statamic's users live in Eloquent, `user` when they are flat files.
    |
    */

    'entitlements' => [
        'subject_type' => env('COURSES_SUBJECT_TYPE'),
    ],

];
