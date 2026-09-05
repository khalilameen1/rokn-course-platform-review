<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentProfileResource;
use App\Models\ProfileUpdateReceipt;
use App\Models\User;
use App\Services\StoredFileDeletionService;
use App\Services\PortfolioShareIdentityService;
use App\Support\UnicodeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final class ProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        app(PortfolioShareIdentityService::class)->ensure($user);
        $profile = new StudentProfileResource($user);

        // Account/settings screens only consume identity and preferences. Keep
        // the default full snapshot for learning clients, but do not build the
        // enrolled-course graph and aggregate history when it was not asked for.
        if (!$request->boolean('include_learning', false)) {
            $request->boolean('include_badges')
                ? $profile->onlyEarnedBadges()
                : $profile->withoutLearningSnapshot();
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل الملف الشخصي',
            'data' => $profile,
        ]);
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
        if (!$request->filled('client_request_id')) {
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
        if ($profileImage && (!$profileImageHash || (int) $profileImage->getSize() <= 0)) {
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
        $clientRequestId = (string) $validated['client_request_id'];
        $existingReceipt = ProfileUpdateReceipt::query()
            ->where('user_id', $user->id)
            ->where('client_request_id', $clientRequestId)
            ->first();
        if ($existingReceipt) {
            abort_unless(hash_equals((string) $existingReceipt->request_fingerprint, $requestFingerprint), 409);
            return $this->profileUpdateResponse($user->fresh(), true);
        }

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

        $newImagePath = null;
        $oldImagePath = $user->profile_image;
        $applied = false;
        $replayed = false;
        try {
            if ($request->hasFile('profile_image')) {
                // Decode and re-encode the raster. This strips metadata and any
                // polyglot payload instead of trusting the extension alone.
                $newImagePath = $this->storeSafeProfileImage($request->file('profile_image'));
                $updateData['profile_image'] = $newImagePath;
            }

            DB::transaction(function () use (
                $user,
                $validated,
                $clientRequestId,
                $requestFingerprint,
                $updateData,
                &$applied,
                &$replayed
            ): void {
                $locked = $user->newQuery()->lockForUpdate()->findOrFail($user->id);
                $receipt = ProfileUpdateReceipt::query()
                    ->where('user_id', $locked->id)
                    ->where('client_request_id', $clientRequestId)
                    ->first();
                if ($receipt) {
                    abort_unless(hash_equals((string) $receipt->request_fingerprint, $requestFingerprint), 409);
                    $replayed = true;
                    return;
                }
                if (array_key_exists('expected_profile_revision', $validated)
                    && $validated['expected_profile_revision'] !== null
                    && (int) $validated['expected_profile_revision'] !== (int) $locked->profile_revision) {
                    throw ValidationException::withMessages([
                        'profile' => ['تغيرت بيانات الحساب على جهاز آخر. أعد تحميلها ثم احفظ من جديد.'],
                    ]);
                }

                $nextRevision = (int) $locked->profile_revision + 1;
                $locked->forceFill($updateData + ['profile_revision' => $nextRevision])->save();
                ProfileUpdateReceipt::query()->create([
                    'user_id' => $locked->id,
                    'client_request_id' => $clientRequestId,
                    'request_fingerprint' => $requestFingerprint,
                    'profile_revision' => $nextRevision,
                ]);
                $applied = true;
            });
        } catch (Throwable $exception) {
            if ($newImagePath) {
                app(StoredFileDeletionService::class)->deleteOrQueue('public', $newImagePath);
            }
            throw $exception;
        }

        if ($newImagePath && !$applied) {
            app(StoredFileDeletionService::class)->deleteOrQueue('public', $newImagePath);
        }
        if ($applied && $newImagePath && $oldImagePath && !filter_var($oldImagePath, FILTER_VALIDATE_URL)) {
            app(StoredFileDeletionService::class)->deleteOrQueue('public', $oldImagePath);
        }

        return $this->profileUpdateResponse($user->fresh(), $replayed);
    }

    private function profileUpdateResponse(User $user, bool $replayed = false): JsonResponse
    {
        // Portfolio URLs are unlisted share capabilities, not discoverable
        // usernames. A legacy/custom alias is rotated before any authenticated
        // profile response exposes it, including replayed updates.
        app(PortfolioShareIdentityService::class)->ensure($user);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تعديل البيانات بنجاح',
            'data' => (new StudentProfileResource($user->fresh()))->withoutLearningSnapshot(),
            'replayed' => $replayed,
        ]);
    }

    public function updateInterests(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'classification_ids' => 'required|array',
            'classification_ids.*' => 'integer|min:1|distinct|exists:classifications,id',
        ]);

        $user = DB::transaction(function () use ($user, $request): User {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->interests()->sync($request->classification_ids);

            return $locked->fresh();
        });

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحديث الاهتمامات بنجاح',
            'data' => (new StudentProfileResource($user))->withoutLearningSnapshot(),
        ]);

    }

    private function storeSafeProfileImage(UploadedFile $file): string
    {
        $image = Image::make($file->getRealPath());
        // EXIF orientation is optional in PHP. A perfectly valid raster must
        // never turn into a 500 merely because a lightweight production image
        // omits the EXIF extension.
        if (function_exists('exif_read_data')) {
            $image->orientate();
        }
        $image->resize(1024, 1024, static function ($constraint): void {
            $constraint->aspectRatio();
            $constraint->upsize();
        });

        $canvas = Image::canvas($image->width(), $image->height(), '#ffffff')
            ->insert($image)
            ->encode('jpg', 86);
        $path = 'profiles/' . Str::uuid() . '.jpg';
        app(StoredFileDeletionService::class)
            ->trackPotentialOrphan('public', $path, 60);

        if (!Storage::disk('public')->put($path, (string) $canvas)) {
            throw new \RuntimeException('Could not store profile image.');
        }

        return $path;
    }

    public function clearWatchHistory(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $deleted = DB::table('watching_logs')->where('user_id', $user->id)->delete();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم مسح سجل المشاهدة',
            'data' => [
                'deleted_entries' => $deleted,
                'course_progress_preserved' => true,
            ],
        ]);
    }
}
