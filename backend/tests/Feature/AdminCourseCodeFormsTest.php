<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CourseCodeResource;
use App\Filament\Resources\CourseCodeResource\Pages\CreateCourseCode;
use App\Filament\Resources\CourseCodeResource\Pages\EditCourseCode;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\User;
use App\Services\CourseStagedAuthoringService;
use App\Support\BusinessClock;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ProductionCourseCodeSchema;
use Tests\TestCase;

final class AdminCourseCodeFormsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProductionCourseCodeSchema::applySqliteBridge();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'مدير الاختبار', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_batch_receipt_normalization_and_cairo_wall_time_round_trip(): void
    {
        $fields = $this->fields();
        $page = Livewire::test(CreateCourseCode::class)->fillForm($fields)->call('create')->assertHasNoFormErrors();
        self::assertSame(3, CourseCode::query()->count());
        self::assertSame(3, CourseCode::query()->distinct()->count('code'));
        self::assertSame(1, DB::table('admin_authoring_create_intents')->where('status', 'completed')->count());
        // A repeated delivery must replay the completed receipt, not create a second batch.
        $page->call('create')->assertHasNoFormErrors();
        self::assertSame(3, CourseCode::query()->count());
        $code = CourseCode::query()->firstOrFail();
        self::assertSame(['rokn.app', 'university.edu'], $code->allowed_email_domains);
        self::assertSame(BusinessClock::localInputToUtc($fields['start_date'])->getTimestamp(), $code->start_date->getTimestamp());
        Livewire::test(EditCourseCode::class, ['record' => $code->id])->assertSet('data.start_date', $fields['start_date'])
            ->fillForm(['name' => 'الدفعة المعتمدة'])->call('save')->assertHasNoFormErrors();
        self::assertSame('الدفعة المعتمدة', $code->fresh()->name);
        self::assertSame($fields['start_date'], BusinessClock::forDateTimeInput($code->fresh()->start_date));
    }

    public function test_native_rules_reject_bounds_past_start_and_reversed_dates_before_creating_a_batch(): void
    {
        $fields = $this->fields();
        Livewire::test(CreateCourseCode::class)->fillForm([...$fields, 'number_of_codes' => 101, 'max_uses' => 0])
            ->call('create')->assertHasFormErrors(['number_of_codes' => 'max', 'max_uses' => 'min']);
        Livewire::test(CreateCourseCode::class)->fillForm([...$fields, 'start_date' => BusinessClock::now()->subDay()->format('Y-m-d\TH:i')])
            ->call('create')->assertHasFormErrors(['start_date' => 'after_or_equal']);
        Livewire::test(CreateCourseCode::class)->fillForm([...$fields, 'expiry_date' => $fields['start_date']])
            ->call('create')->assertHasFormErrors(['expiry_date' => 'after']);
        self::assertSame(0, CourseCode::query()->count());
        Livewire::test(CreateCourseCode::class)->fillForm([...$fields, 'start_date' => null, 'number_of_codes' => 1])
            ->call('create')->assertHasNoFormErrors();
        self::assertSame(1, CourseCode::query()->count());
    }

    public function test_used_contract_cannot_change_but_can_be_named_and_disabled(): void
    {
        Livewire::test(CreateCourseCode::class)->fillForm([...$this->fields(), 'number_of_codes' => 1])->call('create')->assertHasNoFormErrors();
        $code = CourseCode::query()->sole();
        $code->forceFill(['used_count' => 2])->save();
        Livewire::test(EditCourseCode::class, ['record' => $code->id])->fillForm(['is_grant' => false])
            ->call('save')->assertHasFormErrors(['course_id']);
        self::assertTrue($code->fresh()->is_grant);
        Livewire::test(EditCourseCode::class, ['record' => $code->id])->fillForm(['max_uses' => 1])
            ->call('save')->assertHasFormErrors(['max_uses' => 'min']);
        Livewire::test(EditCourseCode::class, ['record' => $code->id])->fillForm(['name' => 'الأرشيف', 'is_active' => false])
            ->call('save')->assertHasNoFormErrors();
        self::assertFalse($code->fresh()->is_active);
        self::assertSame('الأرشيف', $code->fresh()->name);
    }

    public function test_stale_edits_and_revision_targets_never_overwrite_canonical_codes(): void
    {
        $fields = $this->fields();
        Livewire::test(CreateCourseCode::class)->fillForm([...$fields, 'number_of_codes' => 1])->call('create')->assertHasNoFormErrors();
        $code = CourseCode::query()->sole();
        $editor = Livewire::test(EditCourseCode::class, ['record' => $code->id]);
        $code->update(['name' => 'الأحدث']);
        $editor->fillForm(['name' => 'قديم'])->call('save')->assertNotified('تغيّر الكود أثناء التعديل');
        self::assertSame('الأحدث', $code->fresh()->name);
        $revision = app(CourseStagedAuthoringService::class)->draftFor(Course::query()->findOrFail($fields['course_id']));
        Livewire::test(CreateCourseCode::class)->fillForm([...$fields, 'course_id' => $revision->id])
            ->call('create')->assertHasFormErrors(['course_id']);
        self::assertSame(1, CourseCode::query()->count());
    }

    public function test_old_bookmarks_redirect_and_roles_cannot_bypass_native_writers(): void
    {
        Livewire::test(CreateCourseCode::class)->fillForm([...$this->fields(), 'number_of_codes' => 1])->call('create')->assertHasNoFormErrors();
        $code = CourseCode::query()->sole();
        $this->get(route('admin.course-codes.create'))->assertRedirect(CourseCodeResource::getUrl('create'));
        $this->get(route('admin.course-codes.edit', $code))->assertRedirect(CourseCodeResource::getUrl('edit', ['record' => $code->id]));
        $code->update(['type' => 'lesson', 'is_active' => false]);
        Livewire::test(EditCourseCode::class, ['record' => $code->id])->assertForbidden();
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(CreateCourseCode::class)->assertForbidden();
        Livewire::test(EditCourseCode::class, ['record' => $code->id])->assertForbidden();
    }

    private function fields(): array
    {
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس المنحة', 'authoring_version' => 1]);

        return ['type' => 'course', 'name' => 'دفعة الاختبار', 'course_id' => $course->id, 'max_uses' => 10, 'number_of_codes' => 3,
            'is_grant' => true, 'allowed_email_domains' => "@ROKN.APP, university.edu\nrokn.app",
            'start_date' => BusinessClock::now()->addDay()->format('Y-m-d\TH:i'),
            'expiry_date' => BusinessClock::now()->addDays(2)->format('Y-m-d\TH:i')];
    }
}
