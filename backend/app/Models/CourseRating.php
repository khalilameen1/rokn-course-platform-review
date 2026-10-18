<?php

namespace App\Models;

use App\Traits\InvalidatesCourseCatalogue;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $version
 */
final class CourseRating extends Model
{
    use HasFactory, InvalidatesCourseCatalogue, SoftDeletes;

    /** Catalogue cards depend on count/average, not the optional comment. */
    public function shouldInvalidateCourseCatalogue(): bool
    {
        return $this->wasRecentlyCreated
            || ! $this->exists
            || $this->wasChanged(['rating', 'course_id', 'deleted_at']);
    }

    protected $fillable = [
        'user_id',
        'course_id',
        'rating',
        'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        self::saving(function (CourseRating $rating): void {
            if ((int) $rating->rating < 1 || (int) $rating->rating > 5) {
                throw new \DomainException('Course rating must be between one and five.');
            }
            if ($rating->exists && $rating->isDirty(['user_id', 'course_id'])) {
                throw new \DomainException('A course rating cannot change ownership.');
            }
            if ((int) $rating->version < 1) {
                throw new \DomainException('Course rating version is invalid.');
            }
        });
    }

    /**
     * Get the user who rated the course.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the course that was rated.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
