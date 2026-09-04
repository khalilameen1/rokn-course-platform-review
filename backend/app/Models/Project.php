<?php

namespace App\Models;

use App\Traits\ResolvesLocalizedAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory, ResolvesLocalizedAttributes;

    protected $fillable = [
        'requirements_text',
        'requirements_text_ar',
        'requirements_text_en',
        'fallback_review_delay_seconds',
        'is_graduation_project',
        'submission_text_enabled',
        'submission_max_files',
        'submission_allowed_mime_types',
    ];

    /**
     * Get the requirements_text attribute based on Accept-Language header.
     */
    public function getRequirementsTextAttribute()
    {
        if (!array_key_exists('requirements_text_ar', $this->attributes) && !array_key_exists('requirements_text_en', $this->attributes)) {
            return $this->attributes['requirements_text'] ?? null;
        }

        return $this->localizedValue(
            'requirements_text_ar',
            'requirements_text_en',
            'requirements_text'
        );
    }

    /**
     * Get the description attribute (alias for requirements_text) based on Accept-Language header.
     */
    public function getDescriptionAttribute()
    {
        return $this->requirements_text;
    }

    protected $casts = [
        'is_graduation_project' => 'boolean',
        'submission_text_enabled' => 'boolean',
        'fallback_review_delay_seconds' => 'integer',
        'submission_max_files' => 'integer',
        'submission_allowed_mime_types' => 'array',
    ];

    /**
     * Get the section that owns this project.
     */
    public function section()
    {
        return $this->morphOne(CourseSection::class, 'sectionable');
    }

    public function submissions()
    {
        return $this->hasMany(ProjectSubmission::class);
    }

    public function latestSubmissionForUser(int $userId): ?ProjectSubmission
    {
        return app(\App\Services\CourseRevisionLearnerReadService::class)
            ->projectSubmissions($userId, [(int) $this->id])
            ->get((int) $this->id);
    }

    /**
     * Check if a user has passed this project.
     */
    public function userPassed(int $userId): bool
    {
        return app(\App\Services\CourseRevisionLearnerReadService::class)
            ->passedProjectIds($userId, [(int) $this->id])
            ->contains((int) $this->id);
    }

    /**
     * Get the course through the section.
     */
    public function getCourseAttribute()
    {
        return $this->section ? $this->section->course : null;
    }

    /**
     * Get the module through the section.
     */
    public function getModuleAttribute()
    {
        return $this->section ? $this->section->module : null;
    }

    /**
     * Scope to get graduation projects.
     */
    public function scopeGraduationProjects($query)
    {
        return $query->where('is_graduation_project', true);
    }
}
