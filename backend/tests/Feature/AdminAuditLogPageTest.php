<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\AdminAuditLog;
use App\Models\Package;
use App\Models\User;
use App\Services\StudentAccountStateService;
use App\Support\AdminAuditChangeRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAuditLogPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public function test_admin_reads_and_filters_the_audit_trail(): void
    {
        $admin = $this->staff('admin', 'Owner Person');
        $moderator = $this->staff('moderator', 'Content Person');
        $this->entry($admin, 'admin.coupons.update', now()->subDays(3));
        $this->entry($moderator, 'admin.courses.update', now());

        $this->actingAs($admin)->get(route('admin.audit-log.index'))
            ->assertOk()
            ->assertSee('admin.coupons.update')
            ->assertSee('admin.courses.update');
        $this->actingAs($admin)->get(route('admin.audit-log.index', ['actor_id' => $moderator->id]))
            ->assertOk()->assertSee('admin.courses.update')->assertDontSee('admin.coupons.update');
        $this->actingAs($admin)->get(route('admin.audit-log.index', ['action' => 'admin.coupons']))
            ->assertOk()->assertSee('admin.coupons.update')->assertDontSee('admin.courses.update');
        $this->actingAs($admin)->get(route('admin.audit-log.index', ['from' => now()->subDay()->format('Y-m-d')]))
            ->assertOk()->assertSee('admin.courses.update')->assertDontSee('admin.coupons.update');
        $this->actingAs($admin)->get(route('admin.audit-log.index', ['from' => '2026-10-10', 'to' => '2026-10-01']))
            ->assertSessionHasErrors('to');
    }

    public function test_moderators_cannot_read_the_audit_trail_and_page_has_no_mutations(): void
    {
        $this->actingAs($this->staff('moderator', 'Content Person'))
            ->get(route('admin.audit-log.index'))->assertForbidden();
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.audit-log.'));
        self::assertSame(['admin.audit-log.index'], $routes->map->getName()->values()->all());
        self::assertSame(['GET', 'HEAD'], $routes->first()->methods());
    }

    public function test_mutation_records_before_and_after_for_allow_listed_fields_only(): void
    {
        $admin = $this->staff('admin', 'Owner Person');
        $student = User::query()->forceCreate([
            'name' => 'Learner', 'email' => Str::uuid().'@rokn.test', 'role' => 'client', 'active' => true,
            'password' => bcrypt('a-secret-password'),
        ]);
        $version = app(StudentAccountStateService::class)->editorVersion($student->refresh());

        $this->actingAs($admin)->patch(route('admin.users.deactive', $student), [
            'expected_active' => '1', 'state_version' => $version,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $entry = AdminAuditLog::query()->where('route_name', 'admin.users.deactive')->sole();
        self::assertSame([[
            'subject' => 'User', 'id' => (string) $student->id, 'event' => 'updated',
            'old' => ['active' => true], 'attributes' => ['active' => false],
        ]], $entry->changes);
        self::assertStringNotContainsString('password', json_encode($entry->changes));

        $this->actingAs($admin)->get(route('admin.audit-log.index'))
            ->assertOk()->assertSee('active: نعم → لا', false);
    }

    public function test_recorder_ignores_unlisted_fields_and_rolled_back_writes(): void
    {
        $package = Package::query()->create([
            'name_ar' => 'باقة', 'name_en' => 'Pack', 'price' => 100, 'coins' => 1000, 'is_active' => true,
        ]);

        $recorder = AdminAuditChangeRecorder::begin();
        try {
            DB::transaction(function () use ($package): void {
                $package->update(['price' => 150, 'coins' => 2000]);
                throw new \RuntimeException('stale');
            });
        } catch (\RuntimeException) {
        }
        $package->refresh()->update(['name_ar' => 'اسم جديد']);
        $package->update(['coins' => 1500]);
        $entries = $recorder->finish();

        self::assertCount(1, $entries);
        self::assertSame('Package', $entries[0]['subject']);
        self::assertSame(['coins' => 1000], $entries[0]['old']);
        self::assertSame(['coins' => 1500], $entries[0]['attributes']);

        // Nothing is captured once the request's recorder has finished.
        $package->update(['coins' => 1700]);
        self::assertCount(1, $recorder->finish());
    }

    private function entry(User $actor, string $route, \DateTimeInterface $at): void
    {
        AdminAuditLog::query()->create([
            'request_id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'actor_role' => $actor->role,
            'route_name' => $route, 'http_method' => 'PUT', 'path' => 'dashboard/x',
            'route_parameters' => [], 'request_fields' => ['price'], 'response_status' => 302, 'occurred_at' => $at,
        ]);
    }

    private function staff(string $role, string $name): User
    {
        return User::query()->forceCreate([
            'name' => $name, 'email' => Str::uuid().'@rokn.test', 'role' => $role, 'active' => true,
        ]);
    }
}
