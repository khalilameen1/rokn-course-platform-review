<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AdminPermissionMatrix;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

final class AdminHeaderNotificationService
{
    private const VISIBLE_LIMIT = 8;

    public function __construct(
        private readonly AdminPermissionMatrix $permissions
    ) {
    }

    /**
     * @return array{unread_count:int,items:Collection<int,array{id:string,label:string,url:string}>}
     */
    public function for(?User $administrator): array
    {
        if (!$administrator || !$this->permissions->isAdministrator($administrator->role)) {
            return ['unread_count' => 0, 'items' => collect()];
        }

        $query = $administrator->unreadNotifications();
        $unreadCount = (clone $query)->count();
        $notifications = $query
            ->latest('created_at')
            ->limit(self::VISIBLE_LIMIT * 2)
            ->get(['id', 'data', 'created_at']);

        $userIds = $notifications
            ->map(fn (DatabaseNotification $notification): ?int => $this->targetUserId($notification))
            ->filter()
            ->unique()
            ->values();
        $users = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'name_ar', 'name_en'])
            ->keyBy('id');

        $items = $notifications
            ->map(function (DatabaseNotification $notification) use ($users): ?array {
                $userId = $this->targetUserId($notification);
                $user = $userId ? $users->get($userId) : null;
                if (!$user) {
                    return null;
                }

                $name = trim((string) $user->name) ?: 'طالب';

                return [
                    'id' => (string) $notification->id,
                    'label' => 'طلب جديد من ' . $name,
                    'url' => route('admin.users.show', [$userId, 'n_id' => $notification->id]),
                ];
            })
            ->filter()
            ->take(self::VISIBLE_LIMIT)
            ->values();

        return ['unread_count' => $unreadCount, 'items' => $items];
    }

    private function targetUserId(DatabaseNotification $notification): ?int
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $candidate = $data['user_id'] ?? $data['data'] ?? null;
        if (!is_numeric($candidate) || (int) $candidate <= 0) {
            return null;
        }

        return (int) $candidate;
    }
}
