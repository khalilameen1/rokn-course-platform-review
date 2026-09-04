<?php

namespace App\Models;

use App\Traits\HasPhoto;
use App\Traits\InvalidatesCourseCatalogue;
use App\Traits\ResolvesLocalizedAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class Course extends Model
{
    //
    use HasPhoto, HasFactory, ResolvesLocalizedAttributes, InvalidatesCourseCatalogue, SoftDeletes;

    protected $fillable = [
        'name_ar', 'name_en', 'description_ar', 'description_en', 'image', 'grade_id', 'teacher_id', 'store_id',
        'price', 'price_before_discount', 'currency', 'course_type',
        'is_main_course', 'is_coming_soon', 'is_catalog_visible', 'authoring_version', 'authoring_request_id', 'home_sort_order',
        'last_published_authoring_version', 'published_at',
        'catalog_badge_ar', 'catalog_badge_en', 'catalog_badge_tone',
        'search_keywords_ar', 'search_keywords_en', 'search_title_normalized', 'search_terms_normalized',
        'ai_chat_enabled',
        'chat_attachments_enabled', 'chat_attachment_max_files',
        'attachment_prompt_enabled', 'attachment_prompt_at_seconds', 'attachment_prompt_frequency', 'attachment_prompt_title',
        'attachment_prompt_body', 'attachment_prompt_button_text',
        'level_id', 'awards_badge', 'badge_track', 'certificate_text_template_key',
        'created_at', 'updated_at', 'path_id'
    ];
    protected $photoModel = 'App\Models\Photo';
    protected $casts = [
        'is_main_course' => 'boolean',
        'is_coming_soon' => 'boolean',
        'is_catalog_visible' => 'boolean',
        'authoring_version' => 'integer',
        'last_published_authoring_version' => 'integer',
        'published_at' => 'immutable_datetime',
        'home_sort_order' => 'integer',
        'ai_chat_enabled' => 'boolean',
        'chat_attachments_enabled' => 'boolean',
        'chat_attachment_max_files' => 'integer',
        'attachment_prompt_enabled' => 'boolean',
        'attachment_prompt_at_seconds' => 'integer',
        'awards_badge' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Course $course): void {
            if (!$course->isDirty([
                'name_ar', 'name_en', 'description_ar', 'description_en',
                'search_keywords_ar', 'search_keywords_en',
            ])) {
                return;
            }

            $normalizer = app(\App\Services\ArabicSearchNormalizer::class);
            $course->search_title_normalized = $normalizer->normalize(implode(' ', array_filter([
                $course->name_ar,
                $course->name_en,
            ])));
            $course->search_terms_normalized = $normalizer->normalize(implode(' ', array_filter([
                $course->name_ar,
                $course->name_en,
                $course->description_ar,
                $course->description_en,
                $course->search_keywords_ar,
                $course->search_keywords_en,
            ])));
        });
    }

    public function scopeVisibleInCatalog($query)
    {
        // Publication controls learning access. Catalogue visibility controls
        // discovery. Keeping these two states independent lets an existing
        // learner finish an unlisted course without leaking it to home,
        // search, grades, paths or a guessed public details URL.
        return $query->where('is_catalog_visible', true);
    }

    // Computed attributes for backward compatibility
    public function getTitleAttribute() {
        // Check if we're accessing the raw attribute (to avoid infinite loop)
        if (!array_key_exists('name_ar', $this->attributes) && !array_key_exists('name_en', $this->attributes)) {
            return null;
        }

        return $this->localizedValue('name_ar', 'name_en');
    }

    public function getDescriptionAttribute() {
        // Check if we're accessing the raw attribute (to avoid infinite loop)
        if (!array_key_exists('description_ar', $this->attributes) && !array_key_exists('description_en', $this->attributes)) {
            return null;
        }

        return $this->localizedValue('description_ar', 'description_en');
    }

    public function setTitleAttribute($value) {
        $this->attributes['name_ar'] = $value;
        $this->attributes['name_en'] = $value;
    }

    public function setDescriptionAttribute($value) {
        $this->attributes['description_ar'] = $value;
        $this->attributes['description_en'] = $value;
    }

    public function sections()
    {
        return $this->hasMany(CourseSection::class)->orderBy('order');
    }

    /**
     * Get the modules for this course.
     */
    public function modules()
    {
        return $this->hasMany(CourseModule::class)->orderBy('order');
    }

    public function lessons(){
        return $this->hasMany('App\Models\Lesson','list_id','id');
    }

    public function grade(){
        return $this->belongsTo(Grade::class);
    }

    public function level(){
        return $this->belongsTo(Level::class);
    }

    public function socialGroups(){
        return $this->hasMany(SocialGroup::class,'list_id');
    }

    /**
     * Get the course type name in Arabic.
     */
    public function getCourseTypeNameAttribute()
    {
        switch ($this->course_type) {
            case 'online':
                return 'أونلاين';
            default:
                return 'أونلاين';
        }
    }

    /**
     * Get the PDFs associated with this course.
     */
    public function pdfs()
    {
        return $this->hasMany(CoursePdf::class)->ordered();
    }

    /**
     * Get active PDFs for this course.
     */
    public function activePdfs()
    {
        return $this->hasMany(CoursePdf::class)->active()->ordered();
    }

    /**
     * Get the classifications for this course.
     */
    public function classifications()
    {
        return $this->belongsToMany(Classification::class, 'classification_course');
    }

    /**
     * Get the path associated with this course.
     */
    public function coursePath()
    {
        return $this->belongsTo(Path::class, 'path_id');
    }

    /**
     * Get the ratings for this course.
     */
    public function ratings()
    {
        return $this->hasMany(CourseRating::class)
            ->whereBetween('rating', [1, 5]);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * A course is playable only after it leaves draft/coming-soon state and
     * owns at least one live section. Catalogue visibility is deliberately
     * separate: a published course may be hidden from discovery while its
     * existing students keep learning.
     */
    public function isPublishedForLearning(): bool
    {
        if ((bool) $this->is_coming_soon) {
            return false;
        }

        if (array_key_exists('sections_count', $this->attributes)) {
            return (int) $this->attributes['sections_count'] > 0;
        }

        return $this->relationLoaded('sections')
            ? $this->sections->isNotEmpty()
            : $this->sections()->exists();
    }

    public function enrollments()
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function activeEnrollments()
    {
        return $this->enrollments()
            ->whereHas('user', function ($users) {
                $users->whereNull('users.deleted_at')->students();
            })
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function accessPlans()
    {
        return $this->hasMany(CourseAccessPlan::class)->orderBy('sort_order');
    }

    /**
     * Get the average rating for this course.
     */
    public function getAverageRatingAttribute($value): float
    {
        $average = array_key_exists('ratings_avg_rating', $this->attributes)
            ? $this->attributes['ratings_avg_rating']
            : ($this->relationLoaded('ratings')
                ? $this->ratings->avg('rating')
                : $this->ratings()->avg('rating'));

        return $average !== null ? round((float) $average, 1) : 0.0;
    }

    /**
     * Get the total number of ratings for this course.
     */
    public function getRatingsCountAttribute($value): int
    {
        if ($value !== null) {
            return max(0, (int) $value);
        }

        return $this->relationLoaded('ratings')
            ? $this->ratings->count()
            : $this->ratings()->count();
    }
    /**
     * Get the teachers associated with this course.
     */
    public function teachers()
    {
        return $this->belongsToMany(User::class, 'course_teacher', 'course_id', 'teacher_id')
                    // Legacy instructors were administrator accounts before a
                    // dedicated teacher role existed. Keep them visible while
                    // every newly created instructor uses the least-privilege
                    // teacher role.
                    ->whereIn('users.role', ['teacher', 'admin'])
                    ->withTimestamps();
    }

    /**
     * Get the primary teacher for this course (backward compatibility).
     */
    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
