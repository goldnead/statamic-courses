<?php

namespace Goldnead\Courses\Models;

use Goldnead\Courses\Enums\LessonStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Where one learner stands in one lesson.
 *
 * @property int $id
 * @property string $user_id
 * @property string $course_entry_id
 * @property string $course_slug
 * @property string $lesson_entry_id
 * @property string $lesson_slug
 * @property string|null $section_key
 * @property string|null $section_title
 * @property string $status
 * @property int $completion_percent
 * @property int $resume_seconds
 * @property int $watched_seconds
 * @property int|null $video_duration_seconds
 * @property string $manual_completion_state
 * @property array<string, mixed>|null $item_payload
 * @property Carbon|null $first_started_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $completed_at
 */
class LessonState extends Model
{
    protected $table = 'course_lesson_states';

    protected $fillable = [
        'user_id',
        'course_entry_id',
        'course_slug',
        'lesson_entry_id',
        'lesson_slug',
        'section_key',
        'section_title',
        'status',
        'completion_percent',
        'resume_seconds',
        'watched_seconds',
        'video_duration_seconds',
        'manual_completion_state',
        'item_payload',
        'first_started_at',
        'last_activity_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completion_percent' => 'integer',
            'resume_seconds' => 'integer',
            'watched_seconds' => 'integer',
            'video_duration_seconds' => 'integer',
            'item_payload' => 'array',
            'first_started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === LessonStatus::Completed->value;
    }
}
