<?php

namespace App\Models;

use App\Support\PublicDiskUrl;
use App\Support\RoknPublicUrl;

use App\Models\UserNote;
use App\Models\Classification;
use App\Traits\HasPhoto;
use App\Traits\HasApiTokens;
use App\Traits\InvalidatesCourseCatalogue;
use App\Traits\ResolvesLocalizedAttributes;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    use Notifiable, HasPhoto, HasApiTokens, SoftDeletes, ResolvesLocalizedAttributes, InvalidatesCourseCatalogue;

    /**
     * Catalogue rows embed instructor identity. Student/profile/session writes
     * are far more frequent and must not churn every cached home page.
     */
    public function shouldInvalidateCourseCatalogue(): bool
    {
        $currentRole = strtolower((string) $this->role);
        $originalRole = strtolower((string) $this->getRawOriginal('role'));
        $isCatalogueTeacher = in_array($currentRole, ['teacher', 'admin'], true)
            || in_array($originalRole, ['teacher', 'admin'], true);

        // Public cards count enrollments whose owner still exists and has the
        // learner role. Deleting/restoring a learner, or moving an account
        // into/out of that role, changes the count even though ordinary
        // learner profile writes must never churn the global catalogue cache.
        if (!$isCatalogueTeacher) {
            return !$this->exists || $this->wasChanged(['role', 'deleted_at']);
        }

        if (!$this->exists || $this->wasRecentlyCreated) {
            return true;
        }

        return $this->wasChanged([
            'name',
            'name_ar',
            'name_en',
            'job_title',
            'bio',
            'bio_ar',
            'bio_en',
            'profile_image',
            'role',
            'active',
            'deleted_at',
        ]);
    }

    public function whatsappConnection(): HasOne
    {
        return $this->hasOne(UserWhatsAppConnection::class);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password', 'phone', 'gender', 'birthday', 'social_provider', 'social_id',
        'device_os', 'first_name',
        'second_name', 'last_name', 'phone', 'parent_phone', 'parent_job', 'type', 'governorate',
        'profile_image', 'job_title', 'bio',
        'name_ar', 'name_en', 'bio_ar', 'bio_en',
        'notifications_status', 'preferred_locale', 'leaderboard_opt_in', 'last_learning_nudge_at',
        'watch_history_enabled', 'marketing_notifications_enabled',
        'video_quality_preference', 'playback_speed',
        'terms_accepted_at', 'privacy_notice_acknowledged_at', 'legal_notice_version',
        'portfolio_slug', 'portfolio_headline', 'portfolio_location',
        'portfolio_skills', 'portfolio_links',
        'authoring_request_id',
    ];

    /**
     * Get the name based on Accept-Language header.
     *
     * @return string|null
     */
    public function getNameAttribute()
    {
        // Check if we're accessing the raw attribute (to avoid infinite loop)
        if (!array_key_exists('name_ar', $this->attributes) && !array_key_exists('name_en', $this->attributes)) {
            return $this->attributes['name'] ?? null;
        }

        return $this->localizedValue('name_ar', 'name_en', 'name');
    }

    /**
     * Get the bio based on Accept-Language header.
     *
     * @return string|null
     */
    public function getBioAttribute()
    {
        // Check if we're accessing the raw attribute (to avoid infinite loop)
        if (!array_key_exists('bio_ar', $this->attributes) && !array_key_exists('bio_en', $this->attributes)) {
            return $this->attributes['bio'] ?? null;
        }

        return $this->localizedValue('bio_ar', 'bio_en', 'bio');
    }

    /**
     * Get the profile image URL.
     *
     * @return string|null
     */
    public function getProfileImageUrlAttribute(): ?string
    {
        $storedProfileUrl = $this->storedProfileImageUrl();

        // The teacher studio owns instructor portraits through HasPhoto so a
        // replacement portrait must supersede an older imported/social value.
        // Learner and social-account edits still own users.profile_image; an
        // old featured relation must never replace their newer avatar.
        if (strtolower((string) $this->role) === 'teacher') {
            return $this->featuredPhotoUrl() ?? $storedProfileUrl;
        }

        return $storedProfileUrl;
    }

    private function storedProfileImageUrl(): ?string
    {
        $raw = trim((string) ($this->attributes['profile_image'] ?? ''));
        if ($raw === '') {
            return null;
        }
        if (filter_var($raw, FILTER_VALIDATE_URL)) {
            return str_starts_with(strtolower($raw), 'https://') ? $raw : null;
        }

        return PublicDiskUrl::from($raw);
    }

    private function featuredPhotoUrl(): ?string
    {
        $photo = $this->relationLoaded('photo')
            ? $this->getRelation('photo')
            : $this->photo()->first();

        return $photo?->assetPath();
    }

    /**
     * Get the profile deep link for mobile app.
     *
     * @return string|null
     */
    public function getProfileDeeplinkAttribute(): ?string
    {
        if (blank($this->portfolio_slug)) {
            return null;
        }

        return RoknPublicUrl::portfolio((string) $this->portfolio_slug);
    }

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token', 'api_token', 'access_token',
        'admin_totp_secret', 'admin_mfa_backup_codes', 'admin_totp_last_used_step',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'active' => 'boolean',
        'profile_revision' => 'integer',
        'email_verified_at' => 'datetime',
        'portfolio_skills' => 'array',
        'portfolio_links' => 'array',
        'watch_history_enabled' => 'boolean',
        'marketing_notifications_enabled' => 'boolean',
        'playback_speed' => 'float',
        'leaderboard_opt_in' => 'boolean',
        'last_learning_nudge_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'privacy_notice_acknowledged_at' => 'datetime',
        'admin_totp_secret' => 'encrypted',
        'admin_totp_confirmed_at' => 'datetime',
        'admin_totp_last_used_step' => 'integer',
        'admin_mfa_backup_codes' => 'array',
        'last_dashboard_login_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'completed_lessons_count',
        'lesson_progress_statistics'
    ];

    public function notes()
    {
        return $this->hasMany(UserNote::class);
    }

    public function latestNote()
    {
        return $this->hasOne(UserNote::class)->latestOfMany();
    }

    public function addNote()
    {
        return $this->notes()->create([
            'note' => request('note'),
            'created_by' => auth()->id(),
        ]);
    }


    /**
     * @param Builder $builder
     */
    public function scopeActive(Builder $builder)
    {
        return $builder->where('active', true);
    }

    /** The only learner role used by the mobile product. */
    public function scopeStudents(Builder $builder): Builder
    {
        return $builder->whereRaw('LOWER(role) = ?', ['client']);
    }

    public function scopeAdministrators(Builder $builder): Builder
    {
        return $builder->whereRaw('LOWER(role) = ?', ['admin']);
    }


    /**
     * Get student section progress for this user.
     */
    public function sectionProgress()
    {
        return $this->hasMany(StudentSectionProgress::class);
    }

    public function lessonWatchEvidence()
    {
        return $this->hasMany(LessonWatchEvidence::class);
    }

    /**
     * Get count of lessons user has completed.
     */
    public function getCompletedLessonsCountAttribute()
    {
        return $this->sectionProgress()->completed()->count();
    }

    /**
     * Get the course enrollments for the user.
     */
    public function enrollments()
    {
        return $this->hasMany(\App\Models\CourseEnrollment::class);
    }

    /**
     * Get comprehensive lesson progress statistics for the user.
     */
    public function getLessonProgressStatisticsAttribute()
    {
        $totalProgress = $this->sectionProgress();
        $completedProgress = $totalProgress->completed()->get();

        return [
            'total_lessons_accessed' => $totalProgress->count(),
            'completed_lessons' => $completedProgress->count(),
            'completion_rate' => $totalProgress->count() > 0
                ? round(($completedProgress->count() / $totalProgress->count()) * 100, 2)
                : 0
        ];
    }

    /**
     * Get the student notifications for the user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function studentNotifications()
    {
        return $this->hasMany(StudentNotification::class, 'user_id');
    }

    /**
     * Get the count of unread notifications for the user.
     *
     * @return int
     */
    public function unreadNotificationsCount()
    {
        return $this->studentNotifications()->unread()->count();
    }

    /**
     * Get the packages purchased by the user.
     */
    public function purchasedPackages()
    {
        return $this->belongsToMany(Package::class, 'package_user')
                    ->withPivot('order_id', 'price', 'coins', 'created_at')
                    ->withTimestamps();
    }

    /**
     * Check if the user is a premium user (has purchased at least one package).
     *
     * @return bool
     */
    public function isPremiumUser(): bool
    {
        return $this->purchasedPackages()->exists();
    }

    public function portfolioItems()
    {
        return $this->hasMany(PortfolioItem::class);
    }

    public function coinEarnings()
    {
        return $this->hasMany(UserCoinEarning::class);
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function coinTaskAttempts()
    {
        return $this->hasMany(UserCoinTaskAttempt::class);
    }

    public function deviceTokens()
    {
        return $this->hasMany(UserDeviceToken::class);
    }

    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Get the levels earned by the user.
     */
    public function earnedLevels()
    {
        return $this->belongsToMany(Level::class, 'user_level')
            ->withPivot('id', 'earned_at', 'course_id')
            ->withTimestamps();
    }

    /**
     * Check if user has earned a specific level badge.
     *
     * @param int $levelId
     * @return bool
     */
    public function hasEarnedLevel($levelId, $courseId = null)
    {
        return $this->earnedLevels()
            ->where('level_id', $levelId)
            ->when($courseId, fn ($query) => $query->wherePivot('course_id', $courseId))
            ->exists();
    }

    /**
     * Award a level badge to the user.
     *
     * @param int $levelId
     * @param int $courseId
     * @return void
     */
    public function awardLevelBadge($levelId, $courseId)
    {
        if (!$this->hasEarnedLevel($levelId, $courseId)) {
            $this->earnedLevels()->attach($levelId, [
                'earned_at' => now(),
                'course_id' => $courseId,
            ]);
        }
    }

    /**
     * Get the orders for the user.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get orders count for the user.
     */
    public function ordersCount()
    {
        try {
            return $this->orders()->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get the courses that the user teaches.
     */
    public function teachingCourses()
    {
        return $this->belongsToMany(Course::class, 'course_teacher', 'teacher_id', 'course_id')
                    ->withTimestamps();
    }

    /**
     * Get the interests (classifications) for this user.
     */
    public function interests()
    {
        return $this->belongsToMany(Classification::class, 'classification_user')
                    ->withTimestamps();
    }
}
