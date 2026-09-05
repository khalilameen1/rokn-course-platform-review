<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\AdminEditorVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudentAccountStateService
{
    public function setActive(
        User $user,
        bool $expected,
        string $expectedVersion,
        bool $active
    ): User
    {
        return DB::transaction(function () use ($user, $expected, $expectedVersion, $active): User {
            $locked = User::query()->students()->whereKey($user->id)
                ->lockForUpdate()->firstOrFail();

            if (
                (bool) $locked->active !== $expected
                || !hash_equals($this->editorVersion($locked), $expectedVersion)
            ) {
                throw ValidationException::withMessages([
                    'expected_active' => ["تغيّرت حالة الحساب بالفعل\nأعد تحميل الصفحة"],
                ]);
            }

            if ((bool) $locked->active === $active) {
                return $locked;
            }

            $locked->forceFill([
                'active' => $active,
                'profile_revision' => (int) $locked->profile_revision + 1,
                // Clear the retired single-token credential together with
                // every active device credential when access is withdrawn.
                'api_token' => $active ? $locked->getRawOriginal('api_token') : null,
            ])->save();

            if (!$active) {
                $locked->purgeApiTokens();
                $locked->deviceTokens()->delete();
            }

            if ($locked->store) {
                $locked->store->update(['active' => $active]);
            }

            return $locked;
        }, 3);
    }

    public function editorVersion(User $user): string
    {
        return AdminEditorVersion::for($user, ['active', 'profile_revision', 'deleted_at']);
    }
}
