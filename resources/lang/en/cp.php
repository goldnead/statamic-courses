<?php

return [

    'nav' => 'Course Progress',
    'title' => 'Course Progress',
    'permission_view' => 'View course progress',

    'col_title' => 'Course',
    'col_learners' => 'Learners',
    'col_in_progress' => 'In progress',
    'col_completed' => 'Completed',
    'col_completion_rate' => 'Completion rate',
    'col_stuck' => 'Stuck',
    'col_last_activity' => 'Last activity',

    'stuck_help' => 'Started, not finished, no activity for :days days.',
    'open_courses' => 'Open courses',
    'setup_heading' => 'The course tables do not exist yet.',
    'setup_migrate_heading' => 'Run the migrations',
    'setup_migrate_description' => 'php artisan migrate creates them.',
    'empty_heading' => 'There is no course collection yet.',
    'empty_install_heading' => 'Create courses',
    'empty_install_description' => 'Run php artisan courses:install, then add courses and lessons as entries.',
    'empty_docs_heading' => 'Read the documentation',
    'empty_docs_description' => 'Structure, locks, drip and the Antlers tags.',

];
