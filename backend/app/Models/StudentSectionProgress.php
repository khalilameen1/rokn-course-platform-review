<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentSectionProgress extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'course_section_id',
        'is_completed',
        'completed_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the user that owns the section progress.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the course section that this progress belongs to.
     */
    public function courseSection(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class);
    }

    /**
     * Scope to get completed sections for a user.
     */
    public function scopeCompleted($query)
    {
        return $query->where('is_completed', true);
    }

    /**
     * Scope to get progress for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get progress for a specific section.
     */
    public function scopeForSection($query, $sectionId)
    {
        return $query->where('course_section_id', $sectionId);
    }
}
