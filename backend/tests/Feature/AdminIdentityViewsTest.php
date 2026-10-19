<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ArtworkSettings;
use App\Filament\Resources\StudentResource;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Remaining legacy identity workspaces are checked as rendered HTTP responses, not source substrings. */
final class AdminIdentityViewsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->withoutMiddleware(RequireAdminMfa::class);
        $admin = new User;
        $admin->forceFill(['name' => 'مشرف الاختبار', 'role' => 'admin', 'active' => true])->save();
        $this->actingAs($admin, 'web');
    }

    public function test_student_workspaces_render_their_actual_forms_and_shared_theme(): void
    {
        $student = new User;
        $student->forceFill(['name' => 'طالب الاختبار', 'email' => 'identity@example.test', 'role' => 'client', 'active' => true])->save();
        $this->get(route('admin.users.create'))->assertRedirect(StudentResource::getUrl('create'));
        $this->get(route('admin.users.edit', $student))->assertRedirect(StudentResource::getUrl('edit', ['record' => $student->id]));
        $this->get(route('admin.users.show', $student))->assertRedirect(StudentResource::getUrl('view', ['record' => $student->id]));
        $this->get(StudentResource::getUrl('view', ['record' => $student->id]))->assertOk()->assertSee('طالب الاختبار')->assertSee('identity@example.test');
    }

    public function test_design_workspace_renders_the_current_form_contract(): void
    {
        $this->get(route('admin.design-settings.index'))->assertRedirect(ArtworkSettings::getUrl());
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $editor = Livewire::test(ArtworkSettings::class)->assertFormFieldExists('color_1')->assertFormFieldExists('color_4');
        self::assertSame(64, strlen($editor->get('editorVersion')));
    }
}
