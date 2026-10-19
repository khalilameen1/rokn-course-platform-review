<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\StudentResource;
use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentStudentListWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_search_filter_and_csv_have_the_same_students(): void
    {
        $admin = $this->student(['role' => 'admin']);
        $student = $this->student(['name' => 'طالب 100% مميز', 'active' => false]);
        $other = $this->student(['name' => 'طالب عادي']);
        $this->actingAs($admin, 'web');
        $this->withoutMiddleware(RequireAdminMfa::class);
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $page = Livewire::test(ListStudents::class)->searchTable('100%')->filterTable('active', '0');
        $page->assertCanSeeTableRecords([$student])->assertCanNotSeeTableRecords([$other, $admin])
            ->assertTableColumnStateSet('name_ar', 'طالب 100% مميز', $student);
        parse_str(parse_url($page->instance()->exportUrl(), PHP_URL_QUERY), $filters);
        self::assertSame(['search' => '100%', 'active' => '0'], $filters);
        $this->get($page->instance()->exportUrl())->assertOk()->assertDownload();
        $csv = $this->get($page->instance()->exportUrl())->streamedContent();
        self::assertStringContainsString($student->email, $csv);
        self::assertStringNotContainsString($other->email, $csv);
        self::assertStringNotContainsString($admin->email, $csv);
        $page->searchTable('#'.$student->id)->assertCanSeeTableRecords([$student])->assertCanNotSeeTableRecords([$other, $admin]);
    }

    public function test_old_list_url_preserves_search_state_and_page_in_the_native_table(): void
    {
        $this->actingAs($this->student(['role' => 'admin']), 'web');
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->get(route('admin.users.index', ['search' => 'UID: 123', 'active' => '0', 'page' => 3]))
            ->assertRedirect(StudentResource::listUrl(['search' => 'UID: 123', 'active' => '0'], 3));
    }

    private function student(array $data = []): User
    {
        return User::query()->forceCreate([...$data, 'name' => $data['name'] ?? 'طالب القائمة', 'email' => Str::uuid().'@student-list.test',
            'active' => $data['active'] ?? true, 'role' => $data['role'] ?? 'client']);
    }
}
