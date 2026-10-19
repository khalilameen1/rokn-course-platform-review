<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CourseCodeResource;
use App\Filament\Resources\CourseCodeResource\Pages\ListCourseCodes;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\User;
use App\Services\AdminCourseCodeReadService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentCourseCodeListWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $this->actingAs($this->user('admin'), 'web');
    }

    public function test_native_filters_share_the_exact_csv_pdf_and_legacy_url_selection(): void
    {
        $match = $this->code(['name' => 'Design_100%']);
        $other = $this->code(['name' => 'DesignX1000']);
        $inactive = $this->code(['name' => 'Design_100%', 'is_active' => false]);
        $filters = ['name' => '_100%', 'status' => 'active'];
        $page = Livewire::test(ListCourseCodes::class)->filterTable('criteria', $filters)
            ->assertCanSeeTableRecords([$match])->assertCanNotSeeTableRecords([$other, $inactive]);
        self::assertSame(route('admin.course-codes.export', $filters), $page->instance()->exportUrl('csv'));
        self::assertSame(route('admin.course-codes.export-pdf', $filters), $page->instance()->exportUrl('pdf'));
        $this->get(route('admin.course-codes.index', $filters))->assertRedirect(CourseCodeResource::listUrl($filters));
        $this->get($page->instance()->exportUrl('csv'))->assertOk()->assertStreamedContent($this->csv($filters));
    }

    public function test_native_single_delete_preserves_redeemed_codes_and_rejects_stale_versions(): void
    {
        $unused = $this->code();
        Livewire::test(ListCourseCodes::class)->callTableAction('remove', $unused)->assertNotified('تم حذف الكود');
        self::assertNull($unused->fresh());
        $used = $this->code();
        DB::table('course_code_usages')->insert(['tenant_id' => 1, 'course_code_id' => $used->id, 'user_id' => $this->user('client')->id, 'used_at' => now()]);
        Livewire::test(ListCourseCodes::class)->callTableAction('remove', $used)->assertNotified('تم إيقاف الكود مع الاحتفاظ بسجله');
        self::assertFalse($used->fresh()->is_active);
        self::assertSame(1, $used->usages()->count());
        $stale = $this->code();
        $page = Livewire::test(ListCourseCodes::class)->mountTableAction('remove', $stale);
        $stale->update(['name' => 'اسم أحدث']);
        $page->callMountedTableAction()->assertNotified("تغيّر كود الجهة منذ فتح الصفحة\nأعد تحميله قبل الحذف");
        self::assertSame('اسم أحدث', $stale->fresh()->name);
    }

    public function test_bulk_uses_the_domain_owner_and_never_reactivates_old_partial_codes(): void
    {
        $first = $this->code();
        $second = $this->code();
        Livewire::test(ListCourseCodes::class)->callTableBulkAction('deactivate', [$first, $second])->assertNotified('تم تحديث 2 كود');
        self::assertFalse($first->fresh()->is_active);
        self::assertFalse($second->fresh()->is_active);
        $second->update(['type' => 'lesson']);
        Livewire::test(ListCourseCodes::class)->callTableBulkAction('activate', [$first, $second])->assertNotified('تم تحديث 1 كود');
        self::assertTrue($first->fresh()->is_active);
        self::assertFalse($second->fresh()->is_active);
        Livewire::test(ListCourseCodes::class)->callTableBulkAction('delete', [$first, $second]);
        self::assertNull($first->fresh());
        self::assertNull($second->fresh());
    }

    public function test_native_queue_does_not_grant_legacy_moderators_grant_management(): void
    {
        $this->actingAs($this->user('moderator'), 'web')->get(CourseCodeResource::getUrl())->assertForbidden();
    }

    public function test_bulk_confirmation_pins_every_version_before_any_member_changes(): void
    {
        $first = $this->code();
        $second = $this->code();
        $page = Livewire::test(ListCourseCodes::class)->mountTableBulkAction('deactivate', [$first, $second]);
        $second->update(['name' => 'تعديل أحدث']);
        $page->callMountedTableBulkAction()->assertNotified("تغيّر أحد الأكواد المحددة\nأعد تحميل الصفحة قبل المتابعة");
        self::assertTrue($first->fresh()->is_active);
        self::assertTrue($second->fresh()->is_active);
    }

    private function csv(array $filters): string
    {
        $stream = fopen('php://temp', 'w+');
        fprintf($stream, chr(0xEF).chr(0xBB).chr(0xBF));
        foreach (app(AdminCourseCodeReadService::class)->csvRows($filters) as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function code(array $overrides = []): CourseCode
    {
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس منحة', 'authoring_version' => 1]);

        return CourseCode::query()->forceCreate(['tenant_id' => 1, 'code' => Str::upper(Str::random(8)), 'name' => 'دفعة التجربة',
            'course_id' => $course->id, 'type' => 'course', 'max_uses' => 10, 'is_active' => true, ...$overrides]);
    }

    private function user(string $role): User
    {
        return User::query()->forceCreate(['name_ar' => 'مستخدم '.$role, 'email' => Str::uuid().'@grant-list.test', 'role' => $role, 'active' => true]);
    }
}
