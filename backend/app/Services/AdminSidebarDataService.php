<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AdminPermissionMatrix;
use App\Models\Contact;
use App\Models\FeedbackReport;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class AdminSidebarDataService
{
    public function __construct(
        private readonly AdminPermissionMatrix $permissions
    ) {
    }

    /** @return array{is_administrator: bool, pending_support_count: int} */
    public function forUser(?User $user): array
    {
        $isAdministrator = $this->permissions->isAdministrator($user?->role);
        $pendingSupportCount = 0;

        if ($isAdministrator) {
            try {
                if (Schema::hasTable('feedback_reports')) {
                    $pendingSupportCount += FeedbackReport::query()
                        ->whereNotIn('status', ['resolved', 'closed', 'dismissed'])
                        ->count();
                }
                if (Schema::hasTable('contacts')) {
                    $pendingSupportCount += Contact::query()
                        ->where(function ($work): void {
                            $work->where('read', false)
                                ->orWhere(function ($deletion): void {
                                    $deletion->where(function ($type): void {
                                        $type->where('request_type', Contact::TYPE_ACCOUNT_DELETION)
                                            ->orWhere('message', 'like', '[ACCOUNT_DELETION_REQUEST]%');
                                    })->where(function ($status): void {
                                        $status->whereNull('resolution_status')
                                            ->orWhereNotIn('resolution_status', [
                                                Contact::RESOLUTION_CLOSED,
                                                Contact::RESOLUTION_FULFILLED,
                                            ]);
                                    });
                                });
                        })
                        ->count();
                }
            } catch (\Throwable $exception) {
                // Navigation must remain usable while a support store is
                // temporarily unavailable. Its page will expose the
                // actual failure when the administrator opens it.
                report($exception);
            }
        }

        return [
            'is_administrator' => $isAdministrator,
            'pending_support_count' => $pendingSupportCount,
        ];
    }
}
