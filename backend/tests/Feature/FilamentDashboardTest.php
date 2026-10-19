<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AdminSessionIdentity;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\StudentResource;
use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_uses_existing_login_and_rejects_non_staff(): void
    {
        $this->get('/operations/login')->assertRedirect(route('login'));
        $this->get('/operations')->assertRedirect('/operations/login');
        $this->actingAs($this->user('client'), 'web')->get('/operations')->assertForbidden();
    }

    public function test_panel_requires_existing_mfa(): void
    {
        $this->actingAs($this->user('admin'), 'web')->get('/operations')
            ->assertRedirect(route('admin.mfa.setup'));
    }

    public function test_new_panel_renders_lists_and_denies_editor_finance(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($this->user('admin'), 'web');
        foreach (['/operations', '/operations/students', '/operations/orders'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('filament-panels::global-search.field.instructions');
        }
        session()->forget(AdminSessionIdentity::SESSION_KEY);
        $this->actingAs($this->user('moderator'), 'web')->get('/operations')->assertOk();
        $this->get('/operations/students')->assertForbidden();
        $this->get('/operations/orders')->assertForbidden();
    }

    public function test_student_search_is_scoped_and_generic_writes_are_denied(): void
    {
        $admin = $this->user('admin');
        $student = $this->user('client');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $this->actingAs($admin, 'web');
        Livewire::test(ListStudents::class)->assertCanSeeTableRecords([$student])
            ->assertCanNotSeeTableRecords([$admin])->searchTable($student->email)
            ->assertCanSeeTableRecords([$student])->searchTable('not-found@example.test')
            ->assertCanNotSeeTableRecords([$student]);
        self::assertFalse(StudentResource::canCreate());
        self::assertFalse(StudentResource::canDelete($student));
        self::assertFalse(OrderResource::canCreate());
        self::assertFalse(OrderResource::canDeleteAny());
    }

    public function test_changed_identity_invalidates_new_panel_session(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $admin = $this->user('admin');
        $this->actingAs($admin, 'web')->get('/operations')->assertOk();
        $admin->forceFill(['email' => 'changed@example.test'])->save();
        $this->get('/operations')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    private function user(string $role): User
    {
        $user = new User;
        $user->forceFill(['name_ar' => 'حساب '.$role, 'email' => $role.'@panel.example.test', 'role' => $role, 'active' => true])->save();

        return $user;
    }
}
