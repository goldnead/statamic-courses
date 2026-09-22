<?php

namespace Goldnead\Courses\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $user_id
 * @property string $course_entry_id
 * @property int $current_week
 * @property array<int|string, mixed>|null $week_states
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class Enrollment extends Model
{
    protected $table = 'course_enrollments';

    protected $fillable = [
        'user_id',
        'course_entry_id',
        'current_week',
        'week_states',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'current_week' => 'integer',
            'week_states' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
