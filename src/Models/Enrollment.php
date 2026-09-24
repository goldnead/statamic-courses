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
 * @property int $payments_count
 * @property Carbon|null $trial_until
 * @property Carbon|null $drip_paused_at
 * @property int $drip_paused_seconds
 * @property Carbon|null $access_suspended_at
 * @property string|null $suspended_by_subscription_id
 * @property list<string>|null $suspended_grant_refs
 * @property Carbon|null $updated_at
 */
class Enrollment extends Model
{
    protected $table = 'courses_enrollments';

    protected $fillable = [
        'user_id',
        'course_entry_id',
        'current_week',
        'week_states',
        'started_at',
        'completed_at',
        'payments_count',
        'trial_until',
        'drip_paused_at',
        'drip_paused_seconds',
        'access_suspended_at',
        'suspended_by_subscription_id',
        'suspended_grant_refs',
    ];

    protected function casts(): array
    {
        return [
            'current_week' => 'integer',
            'week_states' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'payments_count' => 'integer',
            'trial_until' => 'datetime',
            'drip_paused_at' => 'datetime',
            'drip_paused_seconds' => 'integer',
            'access_suspended_at' => 'datetime',
            'suspended_grant_refs' => 'array',
        ];
    }

    public function isDripPaused(): bool
    {
        return $this->drip_paused_at !== null;
    }

    public function isSuspended(): bool
    {
        return $this->access_suspended_at !== null;
    }
}
