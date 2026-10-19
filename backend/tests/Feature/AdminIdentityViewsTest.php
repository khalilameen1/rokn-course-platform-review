<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        $this->get(route('admin.users.create'))->assertOk()->assertSee('users-form.css', false)
            ->assertSee('admin-identity-theme.js', false)->assertSee('name="email"', false)
            ->assertSee(route('admin.users.store'), false);
        $this->get(route('admin.users.edit', $student))->assertOk()->assertSee('users-editor.css', false)
            ->assertSee('identity@example.test')->assertSee('name="editor_version"', false)
            ->assertSee(route('admin.users.update', $student), false);
        $this->get(route('admin.users.show', $student))->assertOk()->assertSee('users-show.css', false)
            ->assertSee('طالب الاختبار')->assertSee('admin-identity-theme.js', false);
    }

    public function test_design_workspace_renders_the_current_form_contract(): void
    {
        $this->get(route('admin.design-settings.index'))->assertOk()->assertSee('design-settings.css', false)
            ->assertSee(route('admin.design-settings.store'), false)
            ->assertSee('name="color_1"', false)->assertSee('name="color_4"', false)
            ->assertSee('name="editor_version"', false);
    }
}
