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
    | Lessons that need their own proof
    |--------------------------------------------------------------------------
    |
    | A learner cannot tick these off by hand (setLessonCompletion, the POST
    | route). They complete when the code that checked the proof calls
    | Courses::completeLesson(), e.g. after grading a quiz.
    |
    */

    'proof_required_types' => ['quiz', 'assignment', 'reflection'],

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
    | Webhook Manager
    |--------------------------------------------------------------------------
    |
    | With goldnead/statamic-webhook-manager installed, every course event is
    | a trigger an outbound webhook can listen to (courses.quiz_passed, …).
    | Without that addon this does nothing.
    |
    */

    'webhook_manager' => true,

    /*
    |--------------------------------------------------------------------------
    | Lesson downloads
    |--------------------------------------------------------------------------
    |
    | The asset container the download block picks files from. Written into
    | the lesson blueprint by `courses:install`; null takes the site's first
    | container other than the private one. A private download has its own
    | field on statamic-private-media's container (`private-media.source.container`).
    |
    */

    'downloads' => [
        'container' => env('COURSES_DOWNLOADS_CONTAINER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Front-end route
    |--------------------------------------------------------------------------
    |
    | POST /!/courses/progress lets a signed-in learner mark lessons from a
    | template form ({{ courses:form }}). A site with its own endpoints turns it
    | off; the form tag then renders nothing.
    |
    */

    'routes' => [
        'enabled' => (bool) env('COURSES_ROUTES_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Control Panel
    |--------------------------------------------------------------------------
    |
    | The "Course Progress" screen. `stuck_after_days`: a learner who started
    | and has done nothing for this long counts as stuck.
    |
    */

    'cp' => [
        'enabled' => true,
        'stuck_after_days' => 14,
    ],

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
