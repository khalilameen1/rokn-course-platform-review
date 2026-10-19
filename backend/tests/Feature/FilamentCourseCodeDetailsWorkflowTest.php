<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CourseCodeResource;
use App\Filament\Resources\CourseCodeResource\Pages\ViewCourseCode;
use App\Filament\Resources\CourseCodeResource\RelationManagers\UsagesRelationManager;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\CourseCodeUsage;
use App\Models\Lesson;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ProductionCourseCodeSchema;
use Tests\TestCase;

final class FilamentCourseCodeDetailsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProductionCourseCodeSchema::applySqliteBridge();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($this->user('admin'), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_history_is_read_only_paginated_and_eager_loads_students_once(): void
    {
        $code = $this->code();
        $usages = collect(range(1, 31))->map(fn (int $index): CourseCodeUsage => CourseCodeUsage::query()->create([
            'course_code_id' => $code->id, 'user_id' => $this->user('client')->id, 'used_at' => now(), 'ip_address' => '127.0.0.1',
        ]));
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->get(route('admin.course-codes.show', $code))->assertRedirect(CourseCodeResource::getUrl('view', ['record' => $code->id]));
        Livewire::test(ViewCourseCode::class, ['record' => $code->id])->assertSee('كورس المنحة');
        $page = Livewire::test(UsagesRelationManager::class, ['ownerRecord' => $code, 'pageClass' => ViewCourseCode::class])
            ->assertCanSeeTableRecords($usages->sortByDesc('id')->take(25))->assertCanNotSeeTableRecords($usages->take(6));
        self::assertCount(1, array_filter($queries, fn (string $sql): bool => preg_match('/from ["`]?users["`]? where ["`]?users["`]?\.["`]?id["`]? in/i', $sql) === 1));
        $page->call('gotoPage', 2, $page->instance()->getTablePaginationPageName())->assertCanSeeTableRecords($usages->take(6));
        foreach ($queries as $sql) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $sql);
        }
        self::assertSame(31, $code->usages()->count());
    }

    public function test_historical_partial_codes_show_actual_titles_without_calling_a_missing_relation(): void
    {
        $code = $this->code();
        $first = Lesson::query()->create(['list_id' => $code->course_id, 'title_ar' => 'التكوين', 'priority' => 1]);
        $second = Lesson::query()->create(['list_id' => $code->course_id, 'title_ar' => 'الألوان', 'priority' => 2]);
        $code->update(['type' => 'multiple_lessons', 'lesson_ids' => [$second->id, $first->id], 'is_active' => false]);
        Livewire::test(ViewCourseCode::class, ['record' => $code->id])->assertSee('التكوين — الألوان')->assertActionHidden('edit');
        Livewire::test(UsagesRelationManager::class, ['ownerRecord' => $code, 'pageClass' => ViewCourseCode::class])
            ->assertSee('لم يُستخدم الكود بعد');
    }

    public function test_native_delete_retains_used_history_and_rejects_a_stale_detail(): void
    {
        $code = $this->code();
        $page = Livewire::test(ViewCourseCode::class, ['record' => $code->id]);
        $code->update(['name' => 'الأحدث']);
        $page->callAction('remove')->assertNotified("تغيّر كود الجهة منذ فتح الصفحة\nأعد تحميله قبل الحذف");
        self::assertNotNull($code->fresh());
        $usage = CourseCodeUsage::query()->create(['course_code_id' => $code->id, 'user_id' => $this->user('client')->id, 'used_at' => now()]);
        Livewire::test(ViewCourseCode::class, ['record' => $code->id])->callAction('remove')->assertNotified('تم إيقاف الكود مع الاحتفاظ بسجله');
        self::assertFalse($code->fresh()->is_active);
        self::assertNotNull($usage->fresh());
        $unused = $this->code();
        Livewire::test(ViewCourseCode::class, ['record' => $unused->id])->callAction('remove')->assertNotified('تم حذف الكود');
        self::assertNull($unused->fresh());
    }

    public function test_grant_details_remain_forbidden_to_ordinary_moderators(): void
    {
        $code = $this->code();
        $this->actingAs($this->user('moderator'), 'web')->get(CourseCodeResource::getUrl('view', ['record' => $code->id]))->assertForbidden();
    }

    private function code(): CourseCode
    {
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس المنحة', 'authoring_version' => 1]);

        return CourseCode::query()->create(['code' => Str::upper(Str::random(8)), 'type' => 'course', 'course_id' => $course->id, 'max_uses' => 100, 'is_active' => true]);
    }

    private function user(string $role): User
    {
        return User::query()->forceCreate(['name_ar' => 'مستخدم '.$role, 'email' => Str::uuid().'@grants.test', 'role' => $role, 'active' => true]);
    }
}
