<?php

namespace App\Models;

use App\Traits\ResolvesLocalizedAttributes;
use App\Traits\InvalidatesCourseCatalogue;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseSection extends Model
{
    use HasFactory, SoftDeletes, ResolvesLocalizedAttributes, InvalidatesCourseCatalogue;

    protected $fillable = [
        'title',
        'title_ar',
        'title_en',
        'course_id',
        'module_id',
        'section_type',
        'sectionable_type',
        'sectionable_id',
        'order'
    ];

    protected static function booted(): void
    {
        static::saving(function (CourseSection $section): void {
            $section->section_type = match ($section->sectionable_type) {
                Lesson::class => 'lesson',
                Project::class => 'project',
                default => $section->section_type,
            };
        });
    }

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

    /** Get the learner content owned by this position in the course map. */
    public function sectionable()
    {
        return $this->morphTo();
    }

    /**
     * Get the course that owns this section.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the module that owns this section (if any).
     */
    public function module()
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    /**
     * Get the project for this section (if section_type is project).
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'sectionable_id');
    }

    /**
     * Check if this is a project section.
     */
    public function isProject(): bool
    {
        return $this->getSectionType() === 'project';
    }

    /**
     * Check if this is a lesson section.
     */
    public function isLesson(): bool
    {
        return $this->getSectionType() === 'lesson';
    }

    public function getSectionType(): string
    {
        return match ($this->sectionable_type) {
            Lesson::class => 'lesson',
            Project::class => 'project',
            default => 'unsupported',
        };
    }

    /**
     * Check if a user has completed this section.
     */
    public function isCompletedByUser(int $userId): bool
    {
        return app(\App\Services\CourseRevisionLearnerReadService::class)
            ->completedSectionIds($userId, [(int) $this->id])
            ->contains((int) $this->id);
    }

    /**
     * Check if a user has passed the project (if this is a project section).
     */
    public function userPassedProject(int $userId): bool
    {
        if (!$this->isProject() || !$this->project) {
            return true;
        }

        return $this->project->userPassed($userId);
    }
}
