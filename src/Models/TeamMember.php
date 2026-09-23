<?php

namespace Goldnead\Courses\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A learner a buyer let into their course.
 *
 * @property int $id
 * @property string $owner_id
 * @property string $course_entry_id
 * @property string $email
 * @property Carbon|null $created_at
 */
class TeamMember extends Model
{
    protected $table = 'courses_team_members';

    protected $fillable = [
        'owner_id',
        'course_entry_id',
        'email',
    ];
}
