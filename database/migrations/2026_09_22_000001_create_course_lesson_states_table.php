<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per learner and lesson.
 *
 * Taken from adriangoldner.com (create_course_lesson_states_table +
 * add_payload_to_course_lesson_states). Two changes:
 *
 * - `user_id` is a string without a foreign key. Statamic users may live in
 *   flat files with UUIDs, so there is no `users` table to point at.
 * - `resource_id` and `resource_slug` are gone. They pointed at the source
 *   site's own product collection; here the course entry is the anchor.
 *
 * String columns carry explicit lengths so the unique index stays inside
 * InnoDB's key limit under utf8mb4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_lesson_states', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 64);
            $table->string('course_entry_id', 64)->index();
            $table->string('course_slug', 191)->index();
            $table->string('lesson_entry_id', 64);
            $table->string('lesson_slug', 191)->index();
            $table->string('section_key', 191)->nullable();
            $table->string('section_title')->nullable();
            $table->string('status', 32)->default('not_started')->index();
            $table->unsignedTinyInteger('completion_percent')->default(0);
            $table->unsignedInteger('resume_seconds')->default(0);
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->unsignedInteger('video_duration_seconds')->nullable();
            $table->string('manual_completion_state', 32)->default('none');
            $table->json('item_payload')->nullable();
            $table->timestamp('first_started_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lesson_entry_id']);
            $table->index(['user_id', 'course_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_lesson_states');
    }
};
