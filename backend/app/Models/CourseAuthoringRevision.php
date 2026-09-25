<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CourseAuthoringRevision extends Model
{
    public const DRAFT = 'draft';
    public const ARCHIVED = 'archived';

    public const CLASSIFICATION_SNAPSHOT = 'authoring:classification';
    public const CLASSIFICATION_SNAPSHOT_MARKER = 'authoring:classification-snapshot';
    public const HERO_SELECTION_MARKER = 'authoring:hero-selection';
    public const PATH_SNAPSHOT = 'authoring:path-snapshot';

    /** Exactly one working copy can own this slot for a canonical course. */
    public static function draftSlot(int $courseId): string
    {
        return 'course-draft:'.$courseId;
    }

    protected $fillable = [
        'canonical_course_id', 'revision_course_id', 'base_authoring_version',
        'published_authoring_version', 'status', 'active_slot', 'clone_key',
        'published_at', 'retain_until',
    ];

    protected $casts = [
        'base_authoring_version' => 'integer',
        'published_authoring_version' => 'integer',
        'published_at' => 'immutable_datetime',
        'retain_until' => 'immutable_datetime',
    ];

    public function canonicalCourse() { return $this->belongsTo(Course::class, 'canonical_course_id'); }
    public function revisionCourse() { return $this->belongsTo(Course::class, 'revision_course_id'); }
}
