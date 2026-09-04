<?php

namespace App\Models;

use App\Traits\ResolvesLocalizedAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseModule extends Model
{
    use HasFactory, ResolvesLocalizedAttributes;

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'title_ar',
        'title_en',
        'description_ar',
        'description_en',
        'order',
    ];

    /**
     * Get the title attribute based on Accept-Language header.
     */
    public function getTitleAttribute()
    {
        // Check if we're accessing the raw attribute (to avoid infinite loop)
        if (!array_key_exists('title_ar', $this->attributes) && !array_key_exists('title_en', $this->attributes)) {
            return $this->attributes['title'] ?? null;
        }

        return $this->localizedValue('title_ar', 'title_en', 'title');
    }

    /**
     * Get the description attribute based on Accept-Language header.
     */
    public function getDescriptionAttribute()
    {
        // Check if we're accessing the raw attribute (to avoid infinite loop)
        if (!array_key_exists('description_ar', $this->attributes) && !array_key_exists('description_en', $this->attributes)) {
            return $this->attributes['description'] ?? null;
        }

        return $this->localizedValue('description_ar', 'description_en', 'description');
    }

    /**
     * Get the course that owns this module.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the sections in this module.
     */
    public function sections()
    {
        return $this->hasMany(CourseSection::class, 'module_id')->orderBy('order');
    }

    /**
     * Get the project section in this module (if any).
     */
    public function projectSection()
    {
        return $this->hasOne(CourseSection::class, 'module_id')
            ->where('sectionable_type', Project::class);
    }

    /**
     * Check if this module has a project section.
     */
    public function hasProject(): bool
    {
        return $this->sections()
            ->where('sectionable_type', Project::class)
            ->exists();
    }

    /**
     * Get the project for this module (through project section).
     */
    public function getProjectAttribute()
    {
        $projectSection = $this->projectSection;
        return $projectSection ? $projectSection->project : null;
    }

    /**
     * Check if a user has passed this module's project.
     */
    public function userPassedProject(int $userId): bool
    {
        $project = $this->project;

        if (!$project) {
            return true; // No project means module is passable
        }

        return app(\App\Services\CourseRevisionLearnerReadService::class)
            ->passedProjectIds($userId, [(int) $project->id])
            ->contains((int) $project->id);
    }

    /** Get the learner's latest canonical submission for this project. */
    public function getUserProjectSubmission(int $userId): ?ProjectSubmission
    {
        $project = $this->project;

        if (!$project) {
            return null;
        }

        return app(\App\Services\CourseRevisionLearnerReadService::class)
            ->projectSubmissions($userId, [(int) $project->id])
            ->get((int) $project->id);
    }

    /**
     * Scope to order modules.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
