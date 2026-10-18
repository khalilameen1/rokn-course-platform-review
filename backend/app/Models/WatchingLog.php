<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $playback_session_id
 * @property int|null $last_playback_sequence
 */
class WatchingLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'lesson_id',
        'lesson_name',
        'course_id',
        'course_section_id',
        'course_name',
        'position_seconds',
        'duration_seconds',
        'playback_session_id',
        'playback_session_started_at',
        'last_playback_sequence',
        'watched_at',
        'completed_at',
    ];

    protected $casts = [
        'position_seconds' => 'integer',
        'duration_seconds' => 'integer',
        'playback_session_started_at' => 'datetime',
        'last_playback_sequence' => 'integer',
        'watched_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<CourseSection, $this> */
    public function courseSection(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class);
    }
}
