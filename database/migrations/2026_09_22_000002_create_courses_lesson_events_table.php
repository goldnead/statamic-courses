<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only log of what a learner did in a lesson.
 *
 * Taken from adriangoldner.com (create_course_lesson_events_table), with the
 * same two changes as the states table: a string `user_id` and no product
 * columns. `payload_json` is `payload` here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses_lesson_events', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 64)->index();
            $table->string('course_entry_id', 64)->index();
            $table->string('course_slug', 191)->index();
            $table->string('lesson_entry_id', 64);
            $table->string('lesson_slug', 191)->index();
            $table->string('event_type', 64)->index();
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->unsignedInteger('watched_seconds')->nullable();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['course_entry_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses_lesson_events');
    }
};
