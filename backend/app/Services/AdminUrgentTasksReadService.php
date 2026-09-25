<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Grade;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Bounded queue previews and full paginated lists share the same selection. */
final readonly class AdminUrgentTasksReadService
{
    private const PREVIEW_SIZE = 5;
    private const PAGE_SIZE = 20;

    public function __construct(
        private AdminContentInventoryReadService $inventory,
        private StudentAccountStateService $accounts
    ) {
    }

    public function overview(): array
    {
        $pendingCount = $this->pendingQuery()->count();
        $inactiveCount = $this->inactiveQuery()->count();
        $students = $this->inactiveQuery()->limit(self::PREVIEW_SIZE)->get();

        return [
            'pendingOrders' => $this->pendingQuery()->limit(self::PREVIEW_SIZE)->get(),
            'inactiveStudents' => $students,
            'accountStateVersions' => $this->stateVersions($students),
            'stats' => [
                'pending_orders_count' => $pendingCount,
                'inactive_students_count' => $inactiveCount,
                'total_urgent_tasks' => $pendingCount + $inactiveCount,
            ],
            'hasGrades' => Grade::query()->exists(),
            'hasCourses' => $this->inventory->courses()->exists(),
        ];
    }

    public function pendingOrders(int $page = 1): LengthAwarePaginator
    {
        return $this->pendingQuery()->paginate(self::PAGE_SIZE, ['*'], 'page', max(1, $page));
    }

    public function inactiveStudents(int $page = 1): array
    {
        $students = $this->inactiveQuery()->paginate(self::PAGE_SIZE, ['*'], 'page', max(1, $page));

        return ['inactiveStudents' => $students, 'accountStateVersions' => $this->stateVersions($students->getCollection())];
    }

    /** @return Builder<Order> */
    private function pendingQuery(): Builder
    {
        return Order::query()->where('status', Order::STATUS_PENDING)->with(['user', 'course.grade', 'courseCode'])
            ->latest('created_at')->latest('id');
    }

    /** @return Builder<User> */
    private function inactiveQuery(): Builder
    {
        return User::query()->students()->where('active', false)->latest('updated_at')->latest('id');
    }

    /** @param Collection<int,User> $students */
    private function stateVersions(Collection $students): Collection
    {
        return $students->mapWithKeys(fn (User $student): array => [
            $student->id => $this->accounts->editorVersion($student),
        ]);
    }
}
