<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProfileUpdateReceipt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;
use Throwable;

/**
 * Owns learner-editable profile fields and interests.
 *
 * Writes are idempotent per client request id (the Stripe Idempotency-Key
 * model): a replay with the same fingerprint returns the stored outcome, a
 * different payload under the same key is a 409. `profile_revision` is an
 * optimistic-concurrency token, checked under the user row lock.
 */
final readonly class StudentProfileService
{
    public function __construct(
        private StoredFileDeletionService $files,
        private PortfolioShareIdentityService $shareIdentity
    ) {}

    /**
     * @param  array<string, mixed>  $changes  validated column values to write
     * @return array{user: User, replayed: bool}
     */
    public function update(
        User $user,
        string $clientRequestId,
        string $requestFingerprint,
        array $changes,
        ?int $expectedRevision,
        ?UploadedFile $profileImage
    ): array {
        $existingReceipt = ProfileUpdateReceipt::query()
            ->where('user_id', $user->id)
            ->where('client_request_id', $clientRequestId)
            ->first();
        if ($existingReceipt) {
            abort_unless(hash_equals((string) $existingReceipt->request_fingerprint, $requestFingerprint), 409);

            return ['user' => $this->presentable($user->fresh()), 'replayed' => true];
        }

        $newImagePath = null;
        $oldImagePath = $user->profile_image;
        $applied = false;
        $replayed = false;
        try {
            if ($profileImage) {
                // Decode and re-encode the raster. This strips metadata and any
                // polyglot payload instead of trusting the extension alone.
                $newImagePath = $this->storeSafeProfileImage($profileImage);
                $changes['profile_image'] = $newImagePath;
            }

            DB::transaction(function () use (
                $user,
                $expectedRevision,
                $clientRequestId,
                $requestFingerprint,
                $changes,
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
                $currentRevision = (int) $locked->getAttribute('profile_revision');
                if ($expectedRevision !== null && $expectedRevision !== $currentRevision) {
                    throw ValidationException::withMessages([
                        'profile' => ['تغيرت بيانات الحساب على جهاز آخر. أعد تحميلها ثم احفظ من جديد.'],
                    ]);
                }

                $nextRevision = $currentRevision + 1;
                $locked->forceFill($changes + ['profile_revision' => $nextRevision])->save();
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
                $this->files->deleteOrQueue('public', $newImagePath);
            }
            throw $exception;
        }

        if ($newImagePath && ! $applied) {
            $this->files->deleteOrQueue('public', $newImagePath);
        }
        if ($applied && $newImagePath && $oldImagePath && ! filter_var($oldImagePath, FILTER_VALIDATE_URL)) {
            $this->files->deleteOrQueue('public', $oldImagePath);
        }

        return ['user' => $this->presentable($user->fresh()), 'replayed' => $replayed];
    }

    /** @param  array<int, int|string>  $classificationIds */
    public function replaceInterests(User $user, array $classificationIds): User
    {
        return DB::transaction(function () use ($user, $classificationIds): User {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->interests()->sync($classificationIds);

            return $locked->fresh();
        });
    }

    /**
     * Portfolio URLs are unlisted share capabilities, not discoverable
     * usernames. A legacy/custom alias is rotated before any authenticated
     * profile response exposes it, including replayed updates.
     */
    private function presentable(User $user): User
    {
        $this->shareIdentity->ensure($user);

        return $user->fresh();
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
        $path = 'profiles/'.Str::uuid().'.jpg';
        $this->files->trackPotentialOrphan('public', $path, 60);

        if (! Storage::disk('public')->put($path, (string) $canvas)) {
            throw new \RuntimeException('Could not store profile image.');
        }

        return $path;
    }
}
