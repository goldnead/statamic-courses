<?php

namespace Goldnead\Courses\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $user_id
 * @property string $course_entry_id
 * @property string $course_slug
 * @property string $lesson_entry_id
 * @property string $lesson_slug
 * @property string $event_type
 * @property int|null $progress_percent
 * @property int|null $watched_seconds
 * @property string|null $dedupe_key
 * @property array<string, mixed>|null $payload
 * @property Carbon $occurred_at
 */
class LessonEvent extends Model
{
    protected $table = 'courses_lesson_events';

    protected $fillable = [
        'user_id',
        'course_entry_id',
        'course_slug',
        'lesson_entry_id',
        'lesson_slug',
        'event_type',
        'progress_percent',
        'watched_seconds',
        'dedupe_key',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'watched_seconds' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
