<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseAuthoringRevision;
use App\Models\OperatingCostPool;
use App\Models\Setting;
use App\Support\AdminSingletonLock;
use App\Support\OperatingCostEditorVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Invoice mutations; reporting never resolves this owner or changes invoice evidence. */
final class AdminOperatingCostAuthoringService
{
    /**
     * @param array<string, mixed> $data Validated invoice fields, with normalized is_final.
     * @param callable(OperatingCostPool):void $complete Completes the create receipt in the same transaction.
     */
    public function create(array $data, int $actorId, callable $complete): OperatingCostPool
    {
        return DB::transaction(function () use ($data, $actorId, $complete): OperatingCostPool {
            $this->assertInvoiceCourse($data);
            $pool = OperatingCostPool::query()->create([...$data, 'created_by' => $actorId]);
            $complete($pool);

            return $pool;
        }, 3);
    }

    /** @param array<string, mixed> $data Validated invoice fields, with normalized is_final. */
    public function update(int $id, array $data, string $editorVersion): void
    {
        DB::transaction(function () use ($id, $data, $editorVersion): void {
            $pool = OperatingCostPool::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentVersion($pool, $editorVersion, 'الحفظ');
            $this->assertInvoiceCourse($data, $pool);
            $pool->update($data);
        }, 3);
    }

    public function delete(int $id, string $editorVersion): void
    {
        DB::transaction(function () use ($id, $editorVersion): void {
            $pool = OperatingCostPool::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentVersion($pool, $editorVersion, 'الحذف');
            $pool->delete();
        }, 3);
    }

    public function updateExchangeRate(float $rate, string $editorVersion): void
    {
        DB::transaction(function () use ($rate, $editorVersion): void {
            AdminSingletonLock::acquire('settings');
            $setting = Setting::query()->lockForUpdate()->first() ?? new Setting();
            if (!hash_equals(OperatingCostEditorVersion::exchangeRate($setting), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => "تغيّر سعر التحويل منذ فتح الصفحة\nأعد تحميلها قبل الحفظ",
                ]);
            }
            // Only future cost conversions use this setting. Existing invoices
            // retain their own exchange rate and unrelated settings stay untouched.
            $setting->fill(['openrouter_usd_to_egp_rate' => $rate])->save();
        }, 3);
    }

    private function assertCurrentVersion(OperatingCostPool $pool, string $version, string $operation): void
    {
        if (!hash_equals(OperatingCostEditorVersion::for($pool), $version)) {
            throw ValidationException::withMessages([
                'editor_version' => "تغيّرت فاتورة التشغيل منذ فتح الصفحة\nأعد تحميلها قبل {$operation}",
            ]);
        }
    }

    private function assertInvoiceCourse(array $data, ?OperatingCostPool $existing = null): void
    {
        $courseId = $data['course_id'] ?? null;
        // Legacy attribution can be retained, but never newly assigned to an
        // authoring copy. For edits, compare with the current locked invoice.
        if ($courseId === null || ($existing !== null && (int) $existing->course_id === (int) $courseId)) {
            return;
        }
        if (CourseAuthoringRevision::query()->where('revision_course_id', $courseId)->exists()) {
            throw ValidationException::withMessages(['course_id' => 'اختر الكورس الأصلي، وليس نسخة التأليف.']);
        }
    }
}
