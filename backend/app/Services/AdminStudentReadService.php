<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Support\AdminEditorVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds the read models for the student list and student workspace.
 *
 * Mutations deliberately remain outside this class. Keeping every read
 * constrained by the same student id prevents pagination and eager-loaded
 * relations from leaking state from an adjacent student workspace.
 */
final readonly class AdminStudentReadService
{
    public function __construct(
        private StudentAccountStateService $accounts,
        private DeviceLoginService $deviceLogin,
        private PaymentChannelReportService $paymentChannels,
        private CourseFinancialLedgerReportService $financialLedger,
        private WalletQueryService $wallet,
        private StudentProgressSummaryService $progressSummaries,
    ) {
    }

    /**
     * @param array{search?:?string,active?:?string} $filters
     * @param array<string, mixed> $queryParameters
     * @return array{users:LengthAwarePaginator,accountStateVersions:Collection<int, string>}
     */
    public function listing(array $filters, array $queryParameters): array
    {
        $query = User::query()
            ->students()
            ->with(['photo', 'latestNote']);

        if (in_array($filters['active'] ?? null, ['0', '1'], true)) {
            $query->where('active', ($filters['active'] ?? null) === '1');
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $users) use ($search): void {
                $pattern = "%{$search}%";
                $users->where('name', 'like', $pattern)
                    ->orWhere('name_ar', 'like', $pattern)
                    ->orWhere('name_en', 'like', $pattern)
                    ->orWhere('email', 'like', $pattern)
                    ->orWhere('phone', 'like', $pattern);
            });
        }

        $users = $query
            ->orderByDesc('id')
            ->paginate(10)
            ->appends($queryParameters);
        $accountStateVersions = $users->getCollection()->mapWithKeys(
            fn (User $user): array => [
                (int) $user->id => $this->accounts->editorVersion($user),
            ]
        );

        return compact('users', 'accountStateVersions');
    }

    /**
     * @param array<string, mixed> $queryParameters
     * @return array<string, mixed>
     */
    public function workspace(User $user, array $queryParameters): array
    {
        $user->loadCount([
            'deviceTokens',
            'portfolioItems',
            'portfolioItems as public_portfolio_items_count' => fn (Builder $items) =>
                $items->where('is_public', true),
            'enrollments as active_enrollments_count' => fn (Builder $enrollments) =>
                $enrollments->active(),
        ])->load([
            'photo',
            'socialAccounts' => fn ($accounts) => $accounts->orderBy('provider'),
            'interests:id,name_ar',
        ]);

        $orderScope = Order::query()->where('user_id', $user->id);
        $orders = (clone $orderScope)
            ->with(['course', 'package', 'courseCode', 'paymentMethod'])
            ->latest()
            ->latest('id')
            ->paginate(10, ['*'], 'orders_page')
            ->appends($queryParameters);
        $this->financialLedger->attachAllocations($orders->getCollection());

        $orderStatusCounts = (clone $orderScope)
            ->withoutEagerLoads()
            ->reorder()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $orderStats = [
            'approved' => (int) $orderStatusCounts->get(Order::STATUS_APPROVED, 0),
            'pending' => (int) $orderStatusCounts->get(Order::STATUS_PENDING, 0),
        ];

        $notes = $user->notes()
            ->with('createdBy')
            ->latest()
            ->latest('id')
            ->paginate(5, ['*'], 'notes_page')
            ->appends($queryParameters);

        $projectScope = ProjectSubmission::query()->where('user_id', $user->id);
        $projectSubmissions = (clone $projectScope)
            ->with(['project.section.course'])
            ->latest('submitted_at')
            ->latest('id')
            ->paginate(8, ['*'], 'projects_page')
            ->appends($queryParameters);
        $projectStatusCounts = (clone $projectScope)
            ->withoutEagerLoads()
            ->reorder()
            ->selectRaw('review_status, COUNT(*) AS aggregate')
            ->groupBy('review_status')
            ->pluck('aggregate', 'review_status');

        $learning = $this->progressSummaries
            ->latestForUsers(collect([$user]))
            ->get((int) $user->id);

        return [
            'user' => $user,
            'orders' => $orders,
            'notes' => $notes,
            'orderStats' => $orderStats,
            'walletSummary' => $this->wallet->summary($user),
            'paymentMethodLabels' => $this->paymentChannels->labels(),
            'deviceLoginPolicy' => $this->deviceLogin->configuredPolicy(),
            'accountStateVersion' => $this->accounts->editorVersion($user),
            'deviceStateVersion' => AdminEditorVersion::for($user, [
                'locked_device_id', 'profile_revision', 'deleted_at',
            ]),
            'learning' => $learning,
            'projectSubmissions' => $projectSubmissions,
            'projectStatusCounts' => $projectStatusCounts,
        ];
    }
}
