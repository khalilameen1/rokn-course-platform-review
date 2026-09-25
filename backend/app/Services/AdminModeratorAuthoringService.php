<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\ModeratorEditorVersion;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Content-staff identity writes; session MFA and role authorization stay with their owners. */
final class AdminModeratorAuthoringService
{
    /** @param array<string,mixed> $validated @param Closure(User):void $complete */
    public function create(array $validated, Closure $complete): User
    {
        return DB::transaction(function () use ($validated, $complete): User {
            $moderator = new User();
            $moderator->forceFill($this->profileFields($validated) + [
                'email' => strtolower(trim((string) $validated['email'])),
                'password' => Hash::make((string) $validated['password']),
                'role' => 'moderator', 'email_verified_at' => now(),
            ])->save();
            $complete($moderator);

            return $moderator;
        }, 3);
    }

    /** @param array<string,mixed> $validated */
    public function update(int $id, array $validated, string $editorVersion): void
    {
        DB::transaction(function () use ($id, $validated, $editorVersion): void {
            $moderator = User::query()->whereKey($id)->where('role', 'moderator')->lockForUpdate()->firstOrFail();
            if (!hash_equals(ModeratorEditorVersion::for($moderator), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => ["تغيّرت بيانات مسؤول المحتوى منذ فتح الصفحة\nأعد تحميلها قبل الحفظ"],
                ]);
            }
            $updates = $this->profileFields($validated) + [
                'profile_revision' => (int) $moderator->profile_revision + 1,
            ];
            // A profile edit must not change credentials just because a
            // password manager submitted a filled email/password input.
            if ((bool) ($validated['manage_credentials'] ?? false)) {
                if (array_key_exists('email', $validated)) {
                    $email = strtolower(trim((string) $validated['email']));
                    $updates['email'] = $email;
                    if (!hash_equals(strtolower(trim((string) $moderator->email)), $email)) {
                        $updates['email_verified_at'] = null;
                    }
                }
                if (filled($validated['password'] ?? null)) {
                    $updates['password'] = Hash::make((string) $validated['password']);
                }
            }
            $moderator->forceFill($updates)->save();
        }, 3);
    }

    private function profileFields(array $validated): array
    {
        return [
            'name_ar' => trim((string) $validated['name_ar']),
            'name_en' => filled($validated['name_en'] ?? null) ? trim((string) $validated['name_en']) : null,
            'phone' => filled($validated['phone'] ?? null) ? trim((string) $validated['phone']) : null,
            'active' => (bool) ($validated['active'] ?? false),
        ];
    }
}
