<?php

use Goldnead\Courses\Http\Controllers\Cp\ProgressController;
use Illuminate\Support\Facades\Route;

// The switch bites here as well as on the nav item: a hidden entry with a
// reachable URL is not a disabled screen.
if (! config('courses.cp.enabled', true)) {
    return;
}

Route::get('courses/progress', [ProgressController::class, 'index'])
    ->middleware('can:view course progress')
    ->name('courses.progress.index');
