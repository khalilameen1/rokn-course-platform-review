<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserNote;
use Closure;
use Illuminate\Support\Facades\DB;

final class AdminStudentNoteService
{
    /** @param Closure(UserNote):void $complete Completes the note receipt in the same transaction. */
    public function create(int $studentId, string $text, int $actorId, Closure $complete): UserNote
    {
        return DB::transaction(function () use ($studentId, $text, $actorId, $complete): UserNote {
            $student = User::query()->students()->whereKey($studentId)->lockForUpdate()->firstOrFail();
            $note = $student->notes()->create(['note' => $text, 'created_by' => $actorId]);
            $complete($note);

            return $note;
        }, 3);
    }

    /** Permission adapters supply the same students.manage decision for deleting another author's note. */
    public function delete(int $noteId, int $actorId, bool $canManageOthers): bool
    {
        return DB::transaction(function () use ($noteId, $actorId, $canManageOthers): bool {
            $note = UserNote::query()->whereKey($noteId)->lockForUpdate()->firstOrFail();
            if ((int) $note->created_by !== $actorId && ! $canManageOthers) {
                return false;
            }
            $note->delete();

            return true;
        }, 3);
    }
}
