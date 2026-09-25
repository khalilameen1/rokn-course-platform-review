<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Coupon;
use App\Support\BusinessClock;
use App\Support\CouponEditorVersion;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Coupon definition, image ownership and creation receipt share one commit. */
final readonly class AdminCouponAuthoringService
{
    public function __construct(
        private AdminContentInventoryReadService $inventory,
        private StoredFileUploadService $uploads,
        private StoredFileDeletionService $cleanup
    ) {
    }

    /** @param array<string,mixed> $validated
     *  @param Closure(Coupon):void $complete Receipt completion inside the domain transaction.
     */
    public function create(array $validated, string $requestId, ?UploadedFile $image, Closure $complete): Coupon
    {
        $payload = $this->payload($validated);
        $existing = Coupon::withTrashed()->where('authoring_request_id', $requestId)->first();
        if ($existing) $this->assertCreateReplay($existing, $payload, $image, $requestId);
        // Old releases checkpointed the coupon/photo before the receipt. Keep
        // that owned image on resume; unowned orphan bytes are never reused.
        $ownedPath = $existing?->photo?->path;
        $staged = $ownedPath === null
            ? $this->stageImage($image, 'admin-coupon|'.strtolower($requestId))
            : null;
        try {
            return DB::transaction(function () use ($payload, $requestId, $image, $staged, $complete): Coupon {
                $this->lockCourse($payload['course_id']);
                $coupon = Coupon::withTrashed()->where('authoring_request_id', $requestId)
                    ->lockForUpdate()->first();
                if ($coupon) {
                    $this->assertCreateReplay($coupon, $payload, $image, $requestId);
                } else {
                    $coupon = Coupon::query()->create($payload + ['authoring_request_id' => $requestId]);
                }
                if ($staged !== null) {
                    $coupon->allPhotos()->firstOrCreate(['type' => 'featured'], ['path' => $staged]);
                }
                // An owned image observed before the transaction may have been
                // removed by another editor. Never accept a receipt without it.
                if ($image !== null && !$coupon->photo()->exists()) {
                    throw ValidationException::withMessages([
                        'authoring_request_id' => 'تغيّرت صورة الكود أعد فتح النموذج ثم أرسل',
                    ]);
                }
                $coupon->unsetRelation('photo');
                $complete($coupon);

                return $coupon;
            }, 3);
        } catch (\Throwable $exception) {
            if ($staged !== null) $this->cleanup->deleteOrQueue('public', $staged);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $validated */
    public function update(Coupon $coupon, array $validated, string $editorVersion, ?UploadedFile $image): void
    {
        $payload = $this->payload($validated);
        $path = $this->stageImage($image, 'admin-coupon-update|'.$coupon->id.'|'.$editorVersion);
        try {
            DB::transaction(function () use ($coupon, $payload, $editorVersion, $path): void {
                // Same lock order as checkout: course, then coupon. Allow an
                // invalid legacy target to be disabled, never newly activated.
                $retainingDisabled = !(bool) ($payload['active'] ?? false)
                    && $payload['course_id'] === ($coupon->course_id === null ? null : (int) $coupon->course_id);
                $this->lockCourse($payload['course_id'], $retainingDisabled);
                $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
                $this->assertCurrentVersion($locked, $editorVersion, 'الحفظ');
                $locked->update($payload);
                if ($path !== null) {
                    $oldPhotos = $locked->allPhotos()->where('type', 'featured')->lockForUpdate()->get();
                    $newPhoto = $locked->allPhotos()->firstOrCreate(['path' => $path, 'type' => 'featured']);
                    $oldPhotos->where('id', '!=', $newPhoto->id)->each->delete();
                }
            }, 3);
        } catch (\Throwable $exception) {
            if ($path !== null) $this->cleanup->deleteOrQueue('public', $path);
            throw $exception;
        }
    }

    public function delete(int $id, string $editorVersion): void
    {
        DB::transaction(function () use ($id, $editorVersion): void {
            $locked = Coupon::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentVersion($locked, $editorVersion, 'الحذف');
            $locked->delete();
        }, 3);
    }

    private function lockCourse(?int $id, bool $allowRetainedInactiveTarget = false): void
    {
        if ($id === null) return;
        $course = $this->inventory->courses()->whereKey($id)->lockForUpdate()->first();
        if (!$course && !$allowRetainedInactiveTarget) {
            throw ValidationException::withMessages([
                'course_id' => 'اختر الكورس الأصلي وليس نسخة تعديل أو أرشيف',
            ]);
        }
    }

    private function stageImage(?UploadedFile $image, string $identityPrefix): ?string
    {
        if ($image === null) return null;

        return $this->uploads->storeTrackedUpload(
            $image, 'coupons', 'public', 60,
            $identityPrefix.'|'.hash_file('sha256', $image->getRealPath())
        );
    }

    /** @param array<string,mixed> $validated @return array<string,mixed> */
    private function payload(array $validated): array
    {
        $payload = Arr::only($validated, [
            'name_ar', 'name_en', 'code', 'course_id', 'starts_at', 'balance',
            'max_redemptions', 'expiry_date', 'active',
        ]);
        $payload['course_id'] = filled($payload['course_id'] ?? null) ? (int) $payload['course_id'] : null;
        $payload['starts_at'] = BusinessClock::localInputToUtc($payload['starts_at'] ?? null);

        return $payload;
    }

    private function assertCurrentVersion(Coupon $coupon, string $version, string $operation): void
    {
        if (!hash_equals(CouponEditorVersion::for($coupon), $version)) {
            throw ValidationException::withMessages([
                'editor_version' => "تغيّر كود الخصم منذ فتح الصفحة\nأعد تحميله قبل {$operation}",
            ]);
        }
    }

    private function assertCreateReplay(Coupon $coupon, array $payload, ?UploadedFile $image, string $requestId): void
    {
        $candidate = new Coupon($payload);
        $same = !$coupon->trashed();
        foreach (['name_ar', 'name_en', 'code', 'course_id', 'balance', 'max_redemptions', 'active'] as $field) {
            $same = $same && (string) ($coupon->{$field} ?? '') === (string) ($candidate->{$field} ?? '');
        }
        foreach (['starts_at', 'expiry_date'] as $field) {
            $same = $same && $coupon->{$field}?->getTimestamp() === $candidate->{$field}?->getTimestamp();
        }
        $photo = $coupon->photo;
        if ($photo) {
            $same = $same && $image !== null
                && str_starts_with((string) $photo->path, 'coupons/')
                && basename((string) $photo->path) === basename($this->uploads->trackedUploadDestination(
                    $image, 'coupons', 'public',
                    'admin-coupon|'.strtolower($requestId).'|'.hash_file('sha256', $image->getRealPath())
                ));
        }
        if (!$same) {
            throw ValidationException::withMessages([
                'authoring_request_id' => "تغيّرت بيانات الكود\nأعد فتح النموذج ثم أرسل",
            ]);
        }
    }
}
