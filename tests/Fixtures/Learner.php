<?php

namespace Goldnead\Courses\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A host application's Eloquent user, as adriangoldner.com has one
 * (App\Models\User behind Statamic's eloquent user repository).
 */
class Learner extends Authenticatable
{
    protected $table = 'learners';

    protected $guarded = [];
}
