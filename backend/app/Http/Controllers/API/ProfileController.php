<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentProfileResource;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\StudentProfileService;
use App\Services\WatchHistoryRecorder;
use App\Support\LearningReminderSchedule;
use App\Support\UnicodeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProfileController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly StudentProfileService $profiles
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = new StudentProfileResource($user);

        // Account/settings screens only consume identity and preferences. Keep
        // the default full snapshot for learning clients, but do not build the
        // enrolled-course graph and aggregate history when it was not asked for.
        if (! $request->boolean('include_learning', false)) {
            $request->boolean('include_badges')
                ? $profile->onlyEarnedBadges()
                : $profile->withoutLearningSnapshot();
        }

        return $this->responses->success($profile, 'تم تحميل الملف الشخصي');
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        foreach (['name', 'job_title', 'portfolio_headline'] as $field) {
            if ($request->has($field)) {
                $request->merge([$field => UnicodeText::clean($request->input($field), false)]);
            }
        }
        if (! $request->filled('client_request_id')) {
            $candidate = trim((string) $request->header('Idempotency-Key'));
            $request->merge([
                'client_request_id' => Str::isUuid($candidate)
                    ? $candidate
                    : (string) Str::uuid(),
            ]);
        }

        $validated = $request->validate([
            'client_request_id' => 'required|uuid',
            'expected_profile_revision' => 'nullable|integer|min:0',
            // Login providers own these identities. Editing them here would
            // desynchronise the account from its linked social credentials.
            'phone' => 'prohibited',
            'email' => 'prohibited',
            'name' => 'sometimes|required|string|min:2|max:120',
            'job_title' => 'nullable|string|max:255',
            'portfolio_headline' => 'nullable|string|max:160',
            // This controls delivery to the device. The in-app notification
            // history remains available so important account activity is never lost.
            'notifications_status' => 'nullable|boolean',
            'watch_history_enabled' => 'nullable|boolean',
            'marketing_notifications_enabled' => 'nullable|boolean',
            'learning_reminder_hour' => ['required_with:learning_reminder_timezone', 'integer', Rule::in(LearningReminderSchedule::HOURS)],
            'learning_reminder_timezone' => 'required_with:learning_reminder_hour|string|max:64|timezone',
            'preferred_locale' => 'nullable|string|in:ar,en',
            'leaderboard_opt_in' => 'nullable|boolean',
            // Vertical playback always advances using the course sequence.
            // These retired switches used to create an account preference the
            // current player neither exposes nor honours.
            'autoplay_next_enabled' => 'prohibited',
            'video_quality_preference' => 'nullable|string|in:auto,360p,480p,720p,1080p',
            'video_fit_mode' => 'prohibited',
            'playback_speed' => 'nullable|numeric|in:0.5,0.75,1,1.25,1.5,1.75,2',
            'profile_image' => 'nullable|file|min:1|image|mimes:jpeg,png,jpg,webp|mimetypes:image/jpeg,image/png,image/webp|max:2048|dimensions:max_width=6000,max_height=6000',
        ], [
            'name.required' => 'اكتب الاسم الظاهر',
            'name.min' => 'الاسم قصير جدًا',
            'name.max' => 'الاسم يجب ألا يتجاوز 120 حرفًا',
            'job_title.max' => 'مسمى الوظيفة يجب ألا يتجاوز 255 حرفاً',
            'portfolio_headline.max' => 'المسمى المهني طويل جدًا',
            'profile_image.image' => 'يجب أن يكون الملف صورة',
            'profile_image.mimes' => 'يجب أن تكون الصورة من نوع JPEG أو PNG أو WebP',
            'profile_image.max' => 'حجم الصورة يجب ألا يتجاوز 2 ميجابايت',
        ]);

        $profileImage = $request->file('profile_image');
        $profileImageHash = $profileImage
            ? hash_file('sha256', $profileImage->getRealPath())
            : null;
        if ($profileImage && (! $profileImageHash || (int) $profileImage->getSize() <= 0)) {
            throw ValidationException::withMessages([
                'profile_image' => ['تعذّر قراءة الصورة كاملة'],
            ]);
        }
        $requestFingerprint = hash('sha256', json_encode([
            'name' => $request->has('name') ? trim((string) $request->input('name')) : '__missing__',
            'job_title' => $request->has('job_title') ? trim((string) $request->input('job_title')) : '__missing__',
            'portfolio_headline' => $request->has('portfolio_headline') ? trim((string) $request->input('portfolio_headline')) : '__missing__',
            'notifications_status' => $request->has('notifications_status') ? $request->boolean('notifications_status') : '__missing__',
            'watch_history_enabled' => $request->has('watch_history_enabled') ? $request->boolean('watch_history_enabled') : '__missing__',
            'marketing_notifications_enabled' => $request->has('marketing_notifications_enabled') ? $request->boolean('marketing_notifications_enabled') : '__missing__',
            'learning_reminder_hour' => $request->input('learning_reminder_hour', '__missing__'),
            'learning_reminder_timezone' => $request->input('learning_reminder_timezone', '__missing__'),
            'preferred_locale' => $request->input('preferred_locale', '__missing__'),
            'leaderboard_opt_in' => $request->has('leaderboard_opt_in') ? $request->boolean('leaderboard_opt_in') : '__missing__',
            'video_quality_preference' => $request->input('video_quality_preference', '__missing__'),
            'playback_speed' => $request->input('playback_speed', '__missing__'),
            'profile_image' => $profileImage ? [
                'sha256' => $profileImageHash,
                'size' => (int) $profileImage->getSize(),
                'mime' => strtolower((string) $profileImage->getMimeType()),
            ] : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $updateData = [];

        if ($request->has('name')) {
            $updateData['name'] = $request->name;
            // Learner identity has one editable display name. Old localized
            // columns otherwise win in User::getNameAttribute and make a
            // successful social-profile edit appear to have been ignored.
            $updateData['name_ar'] = null;
            $updateData['name_en'] = null;
        }

        if ($request->has('job_title')) {
            $updateData['job_title'] = $request->job_title;
        }

        if ($request->has('portfolio_headline')) {
            $updateData['portfolio_headline'] = $request->input('portfolio_headline');
        }

        if ($request->has('notifications_status')) {
            $updateData['notifications_status'] = $request->boolean('notifications_status');
        }

        if ($request->has('watch_history_enabled')) {
            $updateData['watch_history_enabled'] = $request->boolean('watch_history_enabled');
        }

        if ($request->has('marketing_notifications_enabled')) {
            $updateData['marketing_notifications_enabled'] = $request->boolean('marketing_notifications_enabled');
        }

        if ($request->has('learning_reminder_hour')) {
            $updateData['learning_reminder_hour'] = (int) $validated['learning_reminder_hour'];
            $updateData['learning_reminder_timezone'] = $validated['learning_reminder_timezone'];
        }

        if ($request->has('preferred_locale')) {
            $updateData['preferred_locale'] = $request->string('preferred_locale')->lower()->value();
        }

        if ($request->has('leaderboard_opt_in')) {
            $updateData['leaderboard_opt_in'] = $request->boolean('leaderboard_opt_in');
        }

        foreach (['video_quality_preference'] as $preference) {
            if ($request->has($preference)) {
                $updateData[$preference] = $request->string($preference)->lower()->value();
            }
        }

        if ($request->has('playback_speed')) {
            $updateData['playback_speed'] = (float) $request->input('playback_speed');
        }

        $result = $this->profiles->update(
            $user,
            (string) $validated['client_request_id'],
            $requestFingerprint,
            $updateData,
            isset($validated['expected_profile_revision']) ? (int) $validated['expected_profile_revision'] : null,
            $request->hasFile('profile_image') ? $request->file('profile_image') : null
        );

        return $this->responses->success(
            (new StudentProfileResource($result['user']))->withoutLearningSnapshot(),
            'تم تعديل البيانات بنجاح',
            200,
            ['replayed' => $result['replayed']]
        );
    }

    public function updateInterests(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'classification_ids' => 'required|array',
            'classification_ids.*' => 'integer|min:1|distinct|exists:classifications,id',
        ]);

        $user = $this->profiles->replaceInterests($user, $request->classification_ids);

        return $this->responses->success(
            (new StudentProfileResource($user))->withoutLearningSnapshot(),
            'تم تحديث الاهتمامات بنجاح'
        );
    }

    public function clearWatchHistory(Request $request, WatchHistoryRecorder $history): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $deleted = $history->clear($user);

        return $this->responses->success([
            'deleted_entries' => $deleted,
            'course_progress_preserved' => true,
        ], 'تم مسح سجل المشاهدة');
    }
}
