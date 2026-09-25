<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\StudentEditorVersion;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Dashboard profile authoring, not social identity proof or account/session administration. */
final class AdminStudentAuthoringService
{
    public function __construct(
        private readonly StoredFileUploadService $uploads,
        private readonly StoredFileDeletionService $cleanup
    ) {
    }

    /**
     * @param array{name:string,email:string,phone:string} $data Validated profile fields.
     * @param Closure(User):void $complete Checkpoint and complete the create receipt in this transaction.
     */
    public function create(array $data, string $requestId, ?UploadedFile $image, Closure $complete): User
    {
        // File staging commits its orphan ledger before bytes are written. It
        // must stay outside the profile/photo/receipt transaction.
        $imagePath = null;
        if ($image !== null) {
            $identity = 'admin-student|'.strtolower($requestId).'|'.hash_file('sha256', $image->getRealPath());
            $imagePath = $this->uploads->storeTrackedUpload($image, 'users', 'public', 60, $identity);
            if ($imagePath === '') throw new \RuntimeException('Student image storage failed');
        }
        try {
            return DB::transaction(function () use ($data, $requestId, $imagePath, $complete): User {
                $user = User::withTrashed()->where('authoring_request_id', $requestId)->lockForUpdate()->first();
                if (!$user) {
                    $user = new User();
                    $user->forceFill([
                        'name' => $data['name'], 'email' => strtolower(trim($data['email'])),
                        'phone' => trim($data['phone']), 'authoring_request_id' => $requestId,
                        // The legacy column is non-null, but learners have no password login.
                        'password' => Hash::make(Str::random(64)),
                        'role' => 'client', 'active' => true, 'is_online' => false,
                        // Dashboard creation never proves ownership of this email.
                        'email_verified_at' => null,
                    ])->save();
                }
                if ($imagePath !== null) {
                    $user->allPhotos()->firstOrCreate(['type' => 'featured'], ['path' => $imagePath]);
                }
                $complete($user);

                return $user;
            }, 3);
        } catch (\Throwable $exception) {
            if ($imagePath !== null) $this->cleanup->deleteOrQueue('public', $imagePath);
            throw $exception;
        }
    }

    /** @param array{name:string,email:string,phone:string} $data Validated profile fields. */
    public function update(int $userId, array $data, string $editorVersion): void
    {
        DB::transaction(function () use ($userId, $data, $editorVersion): void {
            $user = User::query()->students()->whereKey($userId)->lockForUpdate()->firstOrFail();
            if (!hash_equals(StudentEditorVersion::for($user), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => ["تغيّرت بيانات الطالب منذ فتح الصفحة\nأعد تحميلها قبل الحفظ"],
                ]);
            }
            $email = strtolower(trim($data['email']));
            $updates = [
                'name' => $data['name'], 'email' => $email, 'phone' => trim($data['phone']),
                'profile_revision' => (int) $user->profile_revision + 1,
            ];
            if (!hash_equals(strtolower(trim((string) $user->email)), $email)) {
                $updates['email_verified_at'] = null;
            }
            $user->forceFill($updates)->save();
        }, 3);
    }
}
