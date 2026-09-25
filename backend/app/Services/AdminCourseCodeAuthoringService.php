<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseCode;
use App\Support\CourseCodeEditorVersion;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Owns code authoring; redemption remains in CourseCodeRedemptionService. */
final class AdminCourseCodeAuthoringService
{
    public function __construct(private readonly AdminContentInventoryReadService $inventory)
    {
    }

    /**
     * @param array<string, mixed> $payload Validated fields with dates normalized to UTC.
     * @param Closure(CourseCode):void $completeIntent Completes once inside the batch transaction.
     */
    public function createBatch(array $payload, int $count, Closure $completeIntent): CourseCode
    {
        if ($count < 1 || $count > 100) {
            throw new \InvalidArgumentException('A course-code batch must contain 1 to 100 codes.');
        }

        return DB::transaction(function () use ($payload, $count, $completeIntent): CourseCode {
            $this->assertCourseTarget(new CourseCode($payload));
            $first = null;
            for ($i = 0; $i < $count; $i++) {
                $created = CourseCode::create([
                    ...$payload,
                    'code' => CourseCode::generateUniqueCode(),
                ]);
                $first ??= $created;
            }
            $completeIntent($first);

            return $first;
        }, 3);
    }

    /** @param array<string, mixed> $payload Validated fields without editor or request metadata. */
    public function update(int $codeId, array $payload, string $editorVersion): void
    {
        DB::transaction(function () use ($codeId, $payload, $editorVersion): void {
            $code = CourseCode::query()->whereKey($codeId)->lockForUpdate()->firstOrFail();
            $this->assertVersion(
                $code, $editorVersion, 'editor_version',
                "تغيّر كود الجهة منذ فتح الصفحة\nأعد تحميله قبل الحفظ"
            );
            $candidate = clone $code;
            $candidate->fill($payload);
            // Redemption locks code then course. Keep the same order, and
            // allow obsolete targets to be disabled without rewriting history.
            if ($candidate->is_active || $candidate->isDirty(['type', 'course_id'])) {
                $this->assertCourseTarget($candidate);
            }
            $code->update($payload);
        }, 3);
    }

    /** Returns true when history requires deactivation rather than deletion. */
    public function delete(int $codeId, string $editorVersion): bool
    {
        return DB::transaction(function () use ($codeId, $editorVersion): bool {
            $code = CourseCode::query()->whereKey($codeId)->lockForUpdate()->firstOrFail();
            $this->assertVersion(
                $code, $editorVersion, 'editor_version',
                "تغيّر كود الجهة منذ فتح الصفحة\nأعد تحميله قبل الحذف"
            );

            return $this->removeLockedCode($code);
        }, 3);
    }

    /**
     * @param list<int|string> $ids Validated distinct existing code IDs.
     * @param array<int|string, string> $versions Version captured for each selected code.
     * @return array{deleted: int, deactivated: int, changed: int}
     */
    public function bulk(string $action, array $ids, array $versions): array
    {
        if (!in_array($action, ['delete', 'activate', 'deactivate'], true)) {
            throw new \InvalidArgumentException('Unknown course-code action.');
        }

        return DB::transaction(function () use ($action, $ids, $versions): array {
            $codes = CourseCode::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($codes->count() !== count(array_unique(array_map('intval', $ids)))) {
                throw ValidationException::withMessages([
                    'selected_codes' => "تغيّرت قائمة الأكواد\nأعد تحميل الصفحة قبل المتابعة",
                ]);
            }
            foreach ($codes as $code) {
                $this->assertVersion(
                    $code, (string) ($versions[$code->id] ?? ''), 'editor_versions',
                    "تغيّر أحد الأكواد المحددة\nأعد تحميل الصفحة قبل المتابعة"
                );
            }

            $eligibleCourseIds = $action === 'activate'
                ? $this->inventory->courses()->whereKey($codes->pluck('course_id')->filter()->unique())
                    ->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id): int => (int) $id)->all()
                : [];
            $result = ['deleted' => 0, 'deactivated' => 0, 'changed' => 0];
            foreach ($codes as $code) {
                if ($action === 'delete') {
                    $result[$this->removeLockedCode($code) ? 'deactivated' : 'deleted']++;
                    continue;
                }
                if ($action === 'activate' && ($code->type !== 'course'
                    || !in_array((int) $code->course_id, $eligibleCourseIds, true))) {
                    continue;
                }
                $target = $action === 'activate';
                if ((bool) $code->is_active !== $target) {
                    $code->forceFill(['is_active' => $target])->save();
                    $result['changed']++;
                }
            }

            return $result;
        }, 3);
    }

    /** The caller holds the code row lock throughout the history check and write. */
    private function removeLockedCode(CourseCode $code): bool
    {
        if ($code->usages()->exists() || $code->orders()->exists()) {
            $code->forceFill(['is_active' => false])->save();

            return true;
        }
        $code->delete();

        return false;
    }

    private function assertVersion(CourseCode $code, string $version, string $field, string $message): void
    {
        if (!hash_equals(CourseCodeEditorVersion::for($code), $version)) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function assertCourseTarget(CourseCode $code): void
    {
        if ($code->type !== 'course' || !$code->course_id
            || !$this->inventory->courses()->whereKey($code->course_id)->lockForUpdate()->first()) {
            throw ValidationException::withMessages([
                'course_id' => 'اختر الكورس الأصلي وليس نسخة تعديل أو أرشيف',
            ]);
        }
    }
}
