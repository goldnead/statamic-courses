<?php

use Goldnead\Courses\Http\Controllers\ProgressController;
use Illuminate\Support\Facades\Route;

/*
 * Mounted by Statamic under /!/courses, inside the `web` group (sessions,
 * CSRF) and named `statamic.courses.*`.
 *
 * `courses.routes.enabled` switches it off. Checked here, so a disabled route
 * does not exist at all, and again in the controller, so a route cache built
 * while it was on cannot keep it open.
 */
if (config('courses.routes.enabled', true)) {
    Route::post('progress', ProgressController::class)
        ->middleware('throttle:60,1')
        ->name('courses.progress');
}
