<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class OperationsMigrationResumeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pulse_creation_resumes_with_existing_values_without_losing_them(): void
    {
        DB::table('pulse_values')->insert(['timestamp' => now()->timestamp, 'type' => 'resume', 'key' => 'retained', 'key_hash' => 'retained', 'value' => 'keep']);
        Schema::drop('pulse_aggregates');
        $migration = require database_path('migrations/2026_10_19_080000_create_pulse_tables.php');
        $migration->up();
        $migration->up();
        self::assertTrue(Schema::hasTable('pulse_aggregates'));
        self::assertSame('keep', DB::table('pulse_values')->where('type', 'resume')->value('value'));
    }

    public function test_permission_creation_resumes_after_roles_without_replacing_their_ids(): void
    {
        $role = DB::table('roles')->insertGetId(['name' => 'retained-role', 'guard_name' => 'web']);
        Schema::drop('role_has_permissions');
        $migration = require database_path('migrations/2026_10_19_081000_create_permission_tables.php');
        $migration->up();
        $migration->up();
        self::assertTrue(Schema::hasTable('role_has_permissions'));
        self::assertSame($role, DB::table('roles')->where('name', 'retained-role')->value('id'));
    }

    public function test_staff_creation_retry_keeps_existing_associations(): void
    {
        $user = User::query()->forceCreate(['role' => 'admin', 'active' => true]);
        $id = DB::table('staff_members')->insertGetId(['user_id' => $user->id]);
        $migration = require database_path('migrations/2026_10_19_082000_create_staff_members_table.php');
        $migration->up();
        self::assertSame($id, DB::table('staff_members')->where('user_id', $user->id)->value('id'));
        self::assertSame(1, DB::table('staff_members')->count());
    }
}
