<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\AdminMfaResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Break-glass recovery for a staff member who lost both the authenticator and
 * the recovery codes (the operator path behind Fortify-style 2FA recovery).
 * It requires server shell access, asks for confirmation unless --force, and
 * writes an admin audit entry with no actor (console).
 */
final class ResetAdminMfa extends Command
{
    protected $signature = 'admin:mfa-reset {email : Email of the admin or moderator account} {--force : Skip the confirmation prompt}';

    protected $description = 'Clear a dashboard account\'s authenticator and recovery codes so it must enrol again';

    public function handle(AdminMfaResetService $resets): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereIn('role', ['admin', 'moderator'])
            ->first();
        if (! $user) {
            $this->error('No admin or moderator account uses that email.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            "Reset MFA for {$user->email} ({$user->role})? They must enrol a new authenticator at next login.",
            false
        )) {
            $this->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        $hadEnrolment = $resets->reset($user);
        AdminAuditLog::query()->create([
            'request_id' => 'console-'.Str::uuid(),
            'actor_id' => null,
            'actor_role' => 'console',
            'route_name' => 'console.admin.mfa-reset',
            'http_method' => 'CLI',
            'path' => 'artisan admin:mfa-reset',
            'route_parameters' => [
                'target_user_id' => (string) $user->getKey(),
                'target_role' => strtolower((string) $user->role),
                'had_enrolment' => $hadEnrolment ? '1' : '0',
            ],
            'request_fields' => [],
            'response_status' => 200,
            'occurred_at' => now(),
        ]);

        $this->info($hadEnrolment
            ? "MFA reset for {$user->email}. They will be asked to enrol at next dashboard login."
            : "{$user->email} had no confirmed MFA; any partial enrolment was cleared.");

        return self::SUCCESS;
    }
}
