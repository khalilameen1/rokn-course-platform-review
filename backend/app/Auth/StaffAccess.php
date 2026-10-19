<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\StaffMember;
use App\Models\User;
use App\Support\DatabaseCapabilities;

/** One permission vocabulary for Filament and the remaining classic workflows. */
final class StaffAccess
{
    public static function permissionLabels(): array
    {
        $groups = ['content' => 'المحتوى', 'projects' => 'مشاريع الطلاب', 'portfolio' => 'البورتفوليو',
            'finance' => 'المالية', 'students' => 'الطلبة', 'support' => 'الدعم', 'rewards' => 'المكافآت',
            'notifications' => 'الإشعارات', 'operations' => 'التشغيل', 'settings' => 'الإعدادات', 'team' => 'فريق العمل'];
        $permissions = ['credentials.manage' => 'إدارة بيانات الدخول'];
        foreach ($groups as $key => $label) {
            $permissions[$key.'.read'] = $label.' — عرض';
            $permissions[$key.'.manage'] = $label.' — إدارة';
        }

        return $permissions;
    }

    /** null means this legacy operator has not been migrated to granular roles yet. */
    public function allows(User $user, string $permission): ?bool
    {
        if (! $user->exists) {
            return null;
        }
        if (! $user->active || ! in_array(strtolower(trim((string) $user->role)), ['admin', 'moderator'], true)) {
            return false;
        }
        $cacheKey = 'staff-permissions:'.$user->getKey();
        $attributes = request()->attributes;
        if (! $attributes->has($cacheKey)) {
            if (! DatabaseCapabilities::hasTable('staff_members')) {
                return null;
            }
            $member = StaffMember::query()->where('user_id', $user->getKey())->with(['roles.permissions', 'permissions'])->first();
            $attributes->set($cacheKey, $member ? $member->getAllPermissions()->pluck('name')->all() : null);
        }
        $permissions = $attributes->get($cacheKey);

        return $permissions === null ? null : in_array($permission, $permissions, true);
    }

    public function allowsRoute(User $user, ?string $route, string $method): ?bool
    {
        $permission = $this->routePermission((string) $route, $method);
        // These pages contain no business permissions; MFA/self-profile stay available.
        if ($permission === 'session') {
            $isMigrated = $this->allows($user, 'content.read');

            return $isMigrated === null ? null : (bool) $user->active;
        }

        return $this->allows($user, $permission ?? '__unmapped_route__');
    }

    public function routePermission(string $route, string $method): ?string
    {
        if ($route === 'admin.dashboard' || str_starts_with($route, 'admin.mfa.')
            || in_array($route, ['admin.admin_data', 'admin.update_admin_data'], true)) {
            return 'session';
        }
        $groups = [
            'portfolio' => ['admin.portfolio-', 'admin.users.portfolio-'],
            'projects' => ['admin.project-submissions.', 'admin.student-progress.'],
            'finance' => ['admin.orders.', 'admin.packages.', 'admin.coupons.', 'admin.course-codes.', 'admin.payment-reconciliation-findings.', 'admin.operating-costs'],
            'students' => ['admin.users.', 'admin.users.notes.', 'admin.users.reset-device'],
            'content' => ['admin.courses.', 'admin.teachers.', 'admin.classifications.', 'admin.paths.', 'admin.levels.', 'admin.media-health.'],
            'rewards' => ['admin.coin-earning-methods.', 'admin.reward-rules.'],
            'notifications' => ['admin.notifications.', 'admin.admin_notifications.'],
            'support' => ['admin.feedback.', 'admin.contacts.'],
            'team' => ['admin.moderators.'],
            'operations' => ['admin.product-operations.', 'admin.product-analytics.', 'admin.audit-log.', 'admin.user-sessions.', 'admin.playback-operations.', 'admin.app-versions.', 'api.visitors.', 'horizon.', 'pulse'],
            'settings' => ['admin.settings', 'admin.design-settings.', 'admin.abouts.', 'admin.privacy', 'admin.policy', 'admin.about'],
        ];
        foreach ($groups as $group => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($route, $prefix)) {
                    return $group.(in_array(strtoupper($method), ['GET', 'HEAD'], true) ? '.read' : '.manage');
                }
            }
        }

        return null;
    }
}
