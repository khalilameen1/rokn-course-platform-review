<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** One credential writer; Filament supplies its already-hashed password. */
final class AdminSelfAccountAuthoringService
{
    public function fromPlaintext(int $staffId, #[\SensitiveParameter] array $data): User
    {
        return $this->change($staffId, $data['email'], filled($data['password'] ?? null) ? Hash::make($data['password']) : null, $data['current_password']);
    }

    public function change(int $staffId, string $email, #[\SensitiveParameter] ?string $passwordHash, #[\SensitiveParameter] string $currentPassword): User
    {
        try {
            return DB::transaction(function () use ($staffId, $email, $passwordHash, $currentPassword): User {
                $staff = User::query()->whereKey($staffId)->lockForUpdate()->firstOrFail();
                abort_unless($staff->active && in_array($staff->role, ['admin', 'moderator'], true), 403);
                if (! Hash::check($currentPassword, $staff->getAuthPassword())) {
                    throw ValidationException::withMessages(['current_password' => 'كلمة السر الحالية غير صحيحة']);
                }
                $staff->email = strtolower(trim($email));
                if ($passwordHash !== null) {
                    $staff->password = $passwordHash;
                }
                $staff->save();

                return $staff;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'البريد مستخدم في حساب آخر']);
        }
    }
}
