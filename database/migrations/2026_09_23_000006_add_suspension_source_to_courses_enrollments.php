<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a suspension came from.
 *
 * `suspended_by_subscription_id`: the subscription whose failed payment shut
 * the course; null for a suspension set by hand. `suspended_grant_refs`: the
 * payment references of that subscription's grants. While suspended, only
 * those grants stop counting: a lifetime grant, a bundle or another
 * subscription still opens the course.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses_enrollments', function (Blueprint $table) {
            $table->string('suspended_by_subscription_id', 64)->nullable();
            $table->json('suspended_grant_refs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courses_enrollments', function (Blueprint $table) {
            $table->dropColumn(['suspended_by_subscription_id', 'suspended_grant_refs']);
        });
    }
};
