<?php

namespace App\Models;

use App\Traits\InvalidatesCourseCatalogue;
use App\Traits\ResolvesLocalizedAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    use HasFactory, ResolvesLocalizedAttributes, InvalidatesCourseCatalogue;
    //	id	list_id	title	description	video_link	file_link1	file_link2	created_at	updated_at
    protected $fillable = [
        'list_id',
        'title',
        'title_ar',
        'title_en',
        'description',
        'description_ar',
        'description_en',
        'is_opened',
        'video_link',
        'video_source_type',
        'bunny_video_id',
        'thumbnail_path',
        'duration_minutes',
        'file_link1',
        'priority',
        'file_link2',
        'created_at',
        'updated_at'
    ];

    /**
     * Get the title attribute based on Accept-Language header.
     */
    public function getTitleAttribute()
    {
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
        if (!array_key_exists('description_ar', $this->attributes) && !array_key_exists('description_en', $this->attributes)) {
            return $this->attributes['description'] ?? null;
        }

        return $this->localizedValue('description_ar', 'description_en', 'description');
    }

    /**
     * Check if this lesson uses Bunny.net for video
     *
     * @return bool
     */
    public function usesBunnyVideo(): bool
    {
        return $this->video_source_type === 'bunny' && !empty($this->bunny_video_id);
    }

    public function course(){
         return $this->belongsTo('App\Models\Course','list_id','id');
    }

    /** Only lessons that still belong to a live, top-level learning graph. */
    public function scopePublishedLearningGraph($query)
    {
        return $query
            ->whereHas('course', function ($courses): void {
                $courses->where('is_coming_soon', false)
                    ->whereHas('sections');
            })
            ->whereHas('courseSection', function ($sections): void {
                $sections->whereColumn('course_sections.course_id', 'lessons.list_id');
            });
    }

    public function courseSection()
    {
        return $this->morphOne(CourseSection::class, 'sectionable');
    }

    public function savedFolders()
    {
        return $this->belongsToMany(SavedFolder::class, 'saved_folder_lessons')
            ->withTimestamps();
    }

    public function mediaState()
    {
        return $this->hasOne(LessonMediaState::class);
    }

    /** A lesson is playable only when its loaded authoritative generation is ready. */
    public function hasReadyMediaState(): bool
    {
        if (!$this->usesBunnyVideo() || !$this->relationLoaded('mediaState')) {
            return false;
        }

        $state = $this->mediaState;
        $lessonGuid = strtolower(trim((string) $this->bunny_video_id));
        $stateGuid = strtolower(trim((string) ($state?->provider_media_id ?? '')));

        return $state !== null
            && $lessonGuid !== ''
            && $stateGuid !== ''
            && hash_equals($lessonGuid, $stateGuid)
            && $state->status === 'ready'
            && $state->last_reconciled_at !== null
            && $state->integrity_status !== 'quarantined';
    }

    public function playbackSessions()
    {
        return $this->hasMany(PlaybackSession::class);
    }

}

