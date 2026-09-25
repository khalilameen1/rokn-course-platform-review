<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\CourseCodeController;
use App\Models\CourseCode;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\Order;
use App\Models\User;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminCourseCodeAuthoringService;
use App\Services\AdminCourseCodeReadService;
use App\Services\CourseStagedAuthoringService;
use App\Support\CourseCodeEditorVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdminCourseCodeAuthoringOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        \Tests\Support\ProductionCourseCodeSchema::applySqliteBridge();
        Http::preventStrayRequests();
        foreach ([CourseCodeController::class, AdminAuthoringCreateIntentService::class] as $httpOwner) {
            $this->app->bind($httpOwner, static function (): never {
                throw new \LogicException('Course-code authoring must not resolve HTTP owners.');
            });
        }
    }

    public function test_batch_completes_one_receipt_in_the_same_transaction(): void
    {
        $completed = [];
        $first = app(AdminCourseCodeAuthoringService::class)->createBatch(
            $this->payload(), 3, static function (CourseCode $first) use (&$completed): void {
                self::assertSame(1, DB::transactionLevel());
                self::assertSame(3, CourseCode::query()->count());
                $completed[] = $first->id;
            }
        );
        self::assertSame([$first->id], $completed);
        self::assertSame(3, CourseCode::query()->distinct()->count('code'));
        self::assertSame('دفعة تدريبية', $first->fresh()->name);
        self::assertSame(0, DB::transactionLevel());
        Http::assertNothingSent();
    }

    public function test_receipt_failure_rolls_back_every_code_and_allows_a_clean_retry(): void
    {
        $writer = app(AdminCourseCodeAuthoringService::class);
        try {
            $writer->createBatch($this->payload(), 3, static function (): never {
                DB::table('admin_singleton_locks')->insert(['lock_key' => 'test-code-receipt']);
                throw new \RuntimeException('receipt failed');
            });
            self::fail('An incomplete receipt must roll back the whole batch.');
        } catch (\RuntimeException $error) {
            self::assertSame('receipt failed', $error->getMessage());
        }
        self::assertSame(0, CourseCode::query()->count());
        self::assertFalse(DB::table('admin_singleton_locks')->where('lock_key', 'test-code-receipt')->exists());
        $writer->createBatch($this->payload(), 3, static function (): void {});
        self::assertSame(3, CourseCode::query()->count());
    }

    public function test_edit_and_delete_use_the_persisted_version_not_a_stale_model(): void
    {
        $code = $this->code();
        $stale = CourseCodeEditorVersion::for($code->fresh());
        $code->fresh()->update(['name' => 'اسم أحدث']);
        $writer = app(AdminCourseCodeAuthoringService::class);
        foreach (['update', 'delete'] as $command) {
            try {
                if ($command === 'update') {
                    $writer->update($code->id, ['name' => 'تعديل قديم'], $stale);
                } else {
                    $writer->delete($code->id, $stale);
                }
                self::fail('Stale code editors must not overwrite current state.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('editor_version', $error->errors());
            }
        }
        self::assertSame('اسم أحدث', $code->fresh()->name);
        $writer->update($code->id, ['description' => 'وصف جديد'], CourseCodeEditorVersion::for($code->fresh()));
        self::assertSame('وصف جديد', $code->fresh()->description);
    }

    public static function deletionCases(): array
    {
        return [
            'single unused' => [false, 'none'], 'bulk unused' => [true, 'none'],
            'single redeemed' => [false, 'usage'], 'bulk redeemed' => [true, 'usage'],
            'single financial history' => [false, 'order'], 'bulk financial history' => [true, 'order'],
        ];
    }

    #[DataProvider('deletionCases')]
    public function test_single_and_bulk_deletion_share_the_history_retention_rule(bool $bulk, string $history): void
    {
        $code = $this->code();
        if ($history !== 'none') {
            $user = User::query()->forceCreate([
                'name' => 'Student', 'email' => 'code-history@rokn.test',
                'role' => 'client', 'active' => true, 'password' => 'test-only',
            ]);
            if ($history === 'usage') {
                DB::table('course_code_usages')->insert([
                    'course_code_id' => $code->id,
                    'user_id' => $user->id, 'used_at' => now(),
                ]);
            } else {
                Order::query()->create([
                    'user_id' => $user->id, 'course_code_id' => $code->id,
                    'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
                    'amount' => 500, 'final_amount' => 500, 'total_coins' => 500,
                    'status' => Order::STATUS_PENDING, 'financial_status' => Order::FINANCIAL_PENDING,
                ]);
            }
        }
        $writer = app(AdminCourseCodeAuthoringService::class);
        $version = CourseCodeEditorVersion::for($code->fresh());
        if ($bulk) {
            $result = $writer->bulk('delete', [$code->id], [$code->id => $version]);
            self::assertSame($history === 'none' ? 1 : 0, $result['deleted']);
            self::assertSame($history === 'none' ? 0 : 1, $result['deactivated']);
        } else {
            self::assertSame($history !== 'none', $writer->delete($code->id, $version));
        }
        if ($history === 'none') {
            self::assertNull($code->fresh());
        } else {
            self::assertFalse($code->fresh()->is_active);
            self::assertSame($code->code, $code->fresh()->code);
            self::assertTrue($history === 'usage' ? $code->usages()->exists() : $code->orders()->exists());
        }
    }

    public function test_bulk_checks_all_versions_before_mutating_any_selected_code(): void
    {
        $first = $this->code();
        $second = $this->code();
        $versions = [$first->id => CourseCodeEditorVersion::for($first->fresh()), $second->id => CourseCodeEditorVersion::for($second->fresh())];
        $second->update(['name' => 'تعديل أحدث']);
        try {
            app(AdminCourseCodeAuthoringService::class)->bulk('deactivate', [$first->id, $second->id], $versions);
            self::fail('Any stale member must reject the whole selection.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_versions', $error->errors());
        }
        self::assertTrue($first->fresh()->is_active);
        self::assertTrue($second->fresh()->is_active);
    }

    public function test_bulk_activation_does_not_reenable_historical_partial_lesson_codes(): void
    {
        $course = $this->code();
        $legacy = $this->code();
        $course->update(['is_active' => false]);
        $legacy->update(['is_active' => false, 'type' => 'lesson']);
        $result = app(AdminCourseCodeAuthoringService::class)->bulk('activate', [$course->id, $legacy->id], [
            $course->id => CourseCodeEditorVersion::for($course->fresh()),
            $legacy->id => CourseCodeEditorVersion::for($legacy->fresh()),
        ]);
        self::assertSame(1, $result['changed']);
        self::assertTrue($course->fresh()->is_active);
        self::assertFalse($legacy->fresh()->is_active);
    }

    public function test_grant_choices_and_creation_use_canonical_courses_not_revision_or_deleted_rows(): void
    {
        $course = $this->course();
        $draft = app(CourseStagedAuthoringService::class)->draftFor($course);
        $unpublished = $this->course();
        $unpublished->update(['is_coming_soon' => true, 'is_catalog_visible' => false]);
        $deleted = $this->course();
        $deleted->delete();
        $writer = app(AdminCourseCodeAuthoringService::class);
        $payload = ['name' => 'منحة', 'type' => 'course', 'max_uses' => 10, 'is_active' => true];
        foreach ([CourseAuthoringRevision::DRAFT, CourseAuthoringRevision::ARCHIVED] as $status) {
            CourseAuthoringRevision::query()->where('revision_course_id', $draft->id)->update([
                'status' => $status,
                'active_slot' => $status === CourseAuthoringRevision::DRAFT
                    ? CourseAuthoringRevision::draftSlot((int) $course->id) : null,
            ]);
            self::assertEqualsCanonicalizing([$course->id, $unpublished->id],
                app(AdminCourseCodeReadService::class)->courseOptions()->modelKeys());
            foreach ([$draft->id, $deleted->id, null, 999999] as $invalidId) {
                try {
                    $writer->createBatch($payload + ['course_id' => $invalidId], 3, static function (): never {
                        self::fail('An invalid grant target cannot complete a receipt.');
                    });
                    self::fail('Grant batches must reference original courses.');
                } catch (ValidationException $error) {
                    self::assertArrayHasKey('course_id', $error->errors());
                }
            }
        }
        self::assertSame(0, CourseCode::query()->count());
        $writer->createBatch($payload + ['course_id' => $unpublished->id], 2, static function (): void {});
        self::assertSame(2, CourseCode::query()->where('course_id', $unpublished->id)->count());
    }

    public function test_grant_update_cannot_move_to_a_revision_and_bulk_activation_skips_invalid_targets(): void
    {
        $valid = $this->code();
        $draft = app(CourseStagedAuthoringService::class)->draftFor($valid->course);
        $writer = app(AdminCourseCodeAuthoringService::class);
        try {
            $writer->update($valid->id, ['course_id' => $draft->id], CourseCodeEditorVersion::for($valid->fresh()));
            self::fail('Retargeting must use the same rule as grant creation.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('course_id', $error->errors());
        }
        self::assertSame((int) $valid->course_id, (int) $valid->fresh()->course_id);
        $valid->update(['is_active' => false]);
        $legacy = CourseCode::query()->create([
            'code' => 'DRAFT-TARGET', 'type' => 'course', 'course_id' => $draft->id,
            'max_uses' => 10, 'is_active' => false,
        ]);
        $result = $writer->bulk('activate', [$valid->id, $legacy->id], [
            $valid->id => CourseCodeEditorVersion::for($valid->fresh()),
            $legacy->id => CourseCodeEditorVersion::for($legacy->fresh()),
        ]);
        self::assertSame(1, $result['changed']);
        self::assertTrue($valid->fresh()->is_active);
        self::assertFalse($legacy->fresh()->is_active);
    }

    public function test_invalid_legacy_grant_can_be_disabled_without_rewriting_its_target(): void
    {
        $draft = app(CourseStagedAuthoringService::class)->draftFor($this->course());
        $legacy = CourseCode::query()->create([
            'code' => 'OLD-TARGET', 'type' => 'course', 'course_id' => $draft->id,
            'max_uses' => 10, 'is_active' => true,
        ]);
        $writer = app(AdminCourseCodeAuthoringService::class);
        $writer->update($legacy->id, ['is_active' => false], CourseCodeEditorVersion::for($legacy->fresh()));
        self::assertFalse($legacy->fresh()->is_active);
        self::assertSame((int) $draft->id, (int) $legacy->fresh()->course_id);
        try {
            $writer->update($legacy->id, ['is_active' => true], CourseCodeEditorVersion::for($legacy->fresh()));
            self::fail('An old invalid grant cannot be activated by an ordinary edit.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('course_id', $error->errors());
        }
    }

    private function payload(): array
    {
        return ['name' => 'دفعة تدريبية', 'type' => 'course', 'max_uses' => 100, 'is_active' => true,
            'course_id' => $this->course()->id];
    }

    private function course(): Course
    {
        return Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس المنحة', 'price' => 100,
            'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => 1, 'last_published_authoring_version' => 1, 'published_at' => now(),
        ]);
    }

    private function code(): CourseCode
    {
        return app(AdminCourseCodeAuthoringService::class)->createBatch(
            $this->payload(), 1, static function (): void {}
        );
    }
}
