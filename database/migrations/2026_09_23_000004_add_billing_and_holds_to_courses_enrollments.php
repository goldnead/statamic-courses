<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the drip and the payment-failure rules need to know about a learner.
 *
 * - `payments_count`, `trial_until`: drip by payments and after the trial.
 *   Written by the payments bridge (or Courses::recordBilling()).
 * - `drip_paused_at`, `drip_paused_seconds`: a paused drip clock and how
 *   long it stood still in total, so relative release dates move by that much.
 * - `access_suspended_at`: the course is shut until the payment arrives.
 *
 * Added columns only, all nullable or defaulted: rows written by 0.1 stay
 * valid and mean what they meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses_enrollments', function (Blueprint $table) {
            $table->unsignedInteger('payments_count')->default(0);
            $table->timestamp('trial_until')->nullable();
            $table->timestamp('drip_paused_at')->nullable();
            $table->unsignedBigInteger('drip_paused_seconds')->default(0);
            $table->timestamp('access_suspended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courses_enrollments', function (Blueprint $table) {
            $table->dropColumn(['payments_count', 'trial_until', 'drip_paused_at', 'drip_paused_seconds', 'access_suspended_at']);
        });
    }
};
