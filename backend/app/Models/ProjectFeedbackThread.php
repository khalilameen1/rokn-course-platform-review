<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProjectFeedbackThread extends Model
{
    protected $guarded = [];

    protected $casts = [
        'can_reply' => 'boolean',
    ];

    /** @return BelongsTo<ProjectSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(ProjectSubmission::class);
    }

    /** @return BelongsTo<CourseEnrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<ProjectFeedbackMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ProjectFeedbackMessage::class, 'thread_id');
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
