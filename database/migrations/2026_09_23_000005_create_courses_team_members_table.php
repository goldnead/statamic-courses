<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team access: one buyer, several learners.
 *
 * A team belongs to a purchase: the buyer and the product they bought (a
 * course's product or a bundle). One team covers every course that product
 * opens, with the same members. A member is kept by email, not by user id, so
 * a buyer can add a colleague who has no account yet.
 *
 * `slot` numbers the seats 1…n. Its unique index is what keeps two requests
 * racing for the last seat from both getting it: the second insert into the
 * same slot fails, on SQLite as on MySQL, where a row lock would not help.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses_team_members', function (Blueprint $table) {
            $table->id();
            $table->string('owner_id', 64);
            $table->string('product', 191);
            $table->string('email', 191)->index();
            $table->unsignedInteger('slot');
            $table->timestamps();

            $table->unique(['owner_id', 'product', 'email']);
            $table->unique(['owner_id', 'product', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses_team_members');
    }
};
