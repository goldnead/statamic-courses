<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a learner started a course.
 *
 * Generalised from adriangoldner.com's plan_enrollments: `plan_slug` became
 * `course_entry_id`, `current_week` and `week_states` stayed. `started_at` is
 * what the schedule drip counts from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses_enrollments', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 64);
            $table->string('course_entry_id', 64)->index();
            $table->unsignedInteger('current_week')->default(1);
            $table->json('week_states')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses_enrollments');
    }
};
