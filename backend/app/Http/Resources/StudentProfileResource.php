<?php

namespace App\Http\Resources;

use App\Services\CourseChatAccessService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class StudentProfileResource extends JsonResource
{
    private bool $includeLearningSnapshot = true;
    private bool $includeEarnedBadges = true;

    /**
     * Authentication and preference mutations only need the account snapshot.
     * Keeping the learning graph out of those latency-sensitive responses also
     * prevents a large course history from turning a successful login into a
     * client timeout.
     */
    public function withoutLearningSnapshot(): static
    {
        $this->includeLearningSnapshot = false;
        $this->includeEarnedBadges = false;

        return $this;
    }

    /** Learning home consumes badges without needing courses and history. */
    public function onlyEarnedBadges(): static
    {
        $this->includeLearningSnapshot = false;
        $this->includeEarnedBadges = true;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        if ($this->includeLearningSnapshot) {
            $this->prepareProfileSnapshot();
        } elseif ($this->includeEarnedBadges) {
            $this->resource->loadMissing('earnedLevels:id,name_ar,name_en,badge_image');
        }
        $attributes = $this->resource->getAttributes();
        $completedLessons = (int) ($attributes['profile_completed_sections_count'] ?? 0);
        $accessedLessons = (int) ($attributes['profile_accessed_sections_count'] ?? 0);
        $wallet = app(\App\Services\WalletService::class)->balances($this->resource);

        $sessionToken = $request->attributes->get('rokn_api_token');
        $sessionSocialProvider = strtolower(trim((string) ($sessionToken?->auth_provider ?? '')));

        return [
            'id' => (integer)$this->id,
            'name' => (string)$this->name,
            'phone' => $this->phone !== null ? (string) $this->phone : null,
            'wallet_coins' => (float) $wallet['total'],
            'wallet_purchased_coins' => $wallet['paid'],
            'wallet_reward_coins' => $wallet['reward'],
            'profile_image' => $this->profile_image_url,
            'profile_revision' => (int) $this->profile_revision,
            'job_title' => $this->job_title,
            'portfolio_slug' => $this->portfolio_slug,
            'portfolio_headline' => $this->portfolio_headline,
            'portfolio_url' => $this->profile_deeplink,
            'email' => $this->publicEmail(),
            'gender' => (string)$this->gender,
            'birthday' => (string)$this->birthday,
            'role' => (string)$this->role,
            'device_os' => $this->device_os,
            'notifications_status' => $this->notifications_status,
            'preferred_locale' => $this->preferred_locale ?: 'ar',
            'leaderboard_opt_in' => (bool) $this->leaderboard_opt_in,
            'watch_history_enabled' => (bool) $this->watch_history_enabled,
            'marketing_notifications_enabled' => (bool) $this->marketing_notifications_enabled,
            'video_quality_preference' => (string) ($this->video_quality_preference ?: 'auto'),
            'playback_speed' => (float) ($this->playback_speed ?: 1),
            'orders_count' => $this->when(
                $this->includeLearningSnapshot,
                (int) ($attributes['profile_orders_count'] ?? 0)
            ),
            'active' => (bool) $this->active,
            // Keep reauthentication tied to the provider which minted this
            // bearer. A linked account's other session may use another one.
            'social_provider' => $sessionSocialProvider !== ''
                ? $sessionSocialProvider
                : (string) $this->social_provider,
            'phone_verified' => !is_null($this->phone_verified_at),
            'phone_verified_at' => $this->phone_verified_at,
            'courses' => $this->when(
                $this->includeLearningSnapshot,
                fn () => $this->getAuthorizedCourses()
            ),
            'lesson_progress' => $this->when($this->includeLearningSnapshot, [
                'completed_lessons' => $completedLessons,
                'total_lessons_accessed' => $accessedLessons,
                'completion_rate' => $accessedLessons > 0
                    ? round(($completedLessons / $accessedLessons) * 100, 2)
                    : 0,
            ]),
            'interests' => $this->when(
                $this->includeLearningSnapshot,
                fn () => $this->getInterestsSafely()
            ),
            'earned_badges' => $this->when(
                $this->includeLearningSnapshot || $this->includeEarnedBadges,
                fn () => $this->getEarnedBadgesSafely()
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function prepareProfileSnapshot(): void
    {
        $user = $this->resource;
        if ((bool) ($user->getAttributes()['profile_snapshot_prepared'] ?? false)) {
            return;
        }

        $user->loadMissing([
                'interests:id,name_ar,name_en',
                'earnedLevels:id,name_ar,name_en,badge_image',
                'enrollments.course',
            ]);
            $courses = $user->enrollments
                ->pluck('course')
                ->filter()
                ->unique('id')
                ->values();
            $courses->loadMissing([
                'photo',
                'classifications',
                'teachers',
                'coursePath',
                'accessPlans' => fn ($plans) => $plans->where('is_active', true),
            ]);
            $courses->loadCount(['sections', 'ratings', 'activeEnrollments']);
            $courses->loadAvg('ratings', 'rating');

            $currentSectionIds = \App\Models\CourseSection::query()
                ->whereIn('course_id', $courses->pluck('id'))
                ->pluck('id');
            $progress = app(\App\Services\CourseRevisionLearnerReadService::class)
                ->sectionProgressRows((int) $user->id, $currentSectionIds);

            $user->setAttribute('profile_orders_count', $user->orders()->count());
            $user->setAttribute('profile_accessed_sections_count', $progress->count());
        $user->setAttribute('profile_completed_sections_count', $progress->where('is_completed', true)->count());

        $user->setAttribute('profile_snapshot_prepared', true);
    }

    private function publicEmail(): ?string
    {
        $email = Str::lower(trim((string) $this->email));
        if (
            $email === ''
            || Str::endsWith($email, '@placeholder.com')
            || Str::endsWith($email, '@accounts.rokn.app')
        ) {
            return null;
        }

        return $email;
    }

    /** Get user interests from the prepared profile graph. */
    protected function getInterestsSafely()
    {
        return $this->interests ? $this->interests->map(function($interest) {
            return [
                'id' => $interest->id,
                'name_ar' => $interest->name_ar,
                'name_en' => $interest->name_en,
            ];
        }) : [];
    }

    /** Get earned badges from the prepared profile graph. */
    protected function getEarnedBadgesSafely()
    {
        $levels = $this->earnedLevels ?: collect();
        $courses = \App\Models\Course::query()
                ->whereIn('id', $levels->pluck('pivot.course_id')->filter()->unique())
                ->get(['id', 'name_ar', 'name_en', 'badge_track'])
                ->keyBy('id');

        return $levels
                ->filter(function($level) use ($courses) {
                    $course = $courses->get($level->pivot->course_id);

                    return $course
                        && in_array($course->badge_track, ['professional', 'freelance'], true);
                })
                ->map(function($level) use ($courses) {
                $course = $courses->get($level->pivot->course_id);
                return [
                    'id' => $level->pivot->id ?: $level->id . '-' . $level->pivot->course_id,
                    'level_id' => $level->id,
                    'name_ar' => $level->name_ar,
                    'name_en' => $level->name_en,
                    'badge_image' => $level->badge_image_url,
                    'course_id' => $level->pivot->course_id,
                    'course_name_ar' => $course?->name_ar,
                    'course_name_en' => $course?->name_en,
                    'track' => $course?->badge_track,
                    'earned_at' => $level->pivot->earned_at,
                ];
            })->values();
    }

    /**
     * Get authorized courses for the user
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    protected function getAuthorizedCourses()
    {
        $courseAccess = app(CourseChatAccessService::class);
        $enrollments = $this->relationLoaded('enrollments')
            ? $this->enrollments
            : $this->enrollments()
                ->with(['course.accessPlans' => fn ($plans) => $plans->where('is_active', true)])
                ->get();

        $courses = $enrollments
            ->pluck('course')
            ->filter()
            ->unique('id')
            ->values();
        $entitlements = $courseAccess->entitlementsFor(
            (int) $this->id,
            $courses->pluck('id')
        );
        $courses = $courses
            ->filter(fn ($course): bool => (bool) (
                $entitlements[(int) $course->id]['has_learning_access'] ?? false
            ))
            ->values();

        app(\App\Services\CourseDurationService::class)->attachMany($courses);

        return \App\Http\Resources\BaseCourseResource::collection($courses);
    }

}
