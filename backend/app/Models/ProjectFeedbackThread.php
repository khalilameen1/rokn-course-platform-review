<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ProjectFeedbackThread extends Model
{
    protected $guarded = [];

    protected $casts = [
        'can_reply' => 'boolean',
    ];

    public function submission()
    {
        return $this->belongsTo(ProjectSubmission::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function messages()
    {
        return $this->hasMany(ProjectFeedbackMessage::class, 'thread_id');
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
