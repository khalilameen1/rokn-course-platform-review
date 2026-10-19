<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StaffRoleService;
use Illuminate\Console\Command;

final class PrepareStaffRoles extends Command
{
    protected $signature = 'staff:prepare-roles';

    protected $description = 'Create default granular staff roles without changing existing operator access';

    public function handle(StaffRoleService $roles): int
    {
        $roles->seedDefaults();
        $this->info('Staff roles are ready. Existing operators retain their access until explicitly assigned.');

        return self::SUCCESS;
    }
}
