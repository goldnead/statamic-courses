<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team access: one buyer, several learners.
 *
 * A member is kept by email, not by user id, so a buyer can add a colleague
 * who has no account yet; the member gets in once they sign in with that
 * address. The member's access lasts exactly as long as the owner's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses_team_members', function (Blueprint $table) {
            $table->id();
            $table->string('owner_id', 64)->index();
            $table->string('course_entry_id', 64)->index();
            $table->string('email', 191)->index();
            $table->timestamps();

            $table->unique(['owner_id', 'course_entry_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses_team_members');
    }
};
