<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentNotificationResource;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\StudentNotification;
use App\Models\User;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentNotificationController extends Controller
{
    public function __construct(private readonly ApiResponseService $responses)
    {
    }

    public function getAll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
            'filter' => 'nullable|in:read,unread',
            'pagination_mode' => 'nullable|in:cursor',
            'cursor' => 'nullable|string|max:2048',
        ]);

        try {
            /** @var User|null $user */
            $user = auth('api')->user();
            if (!$user) {
                return $this->responses->error('سجّل الدخول أولًا', 401);
            }

            $query = StudentNotification::where('user_id', $user->id)
                ->with('notifiable')
                ->orderByDesc('created_at')
                ->orderByDesc('id');
            $filter = $validated['filter'] ?? null;
            if ($filter === 'read') {
                $query->read();
            } elseif ($filter === 'unread') {
                $query->unread();
            }

            $useCursor = ($validated['pagination_mode'] ?? null) === 'cursor';
            $notifications = $useCursor
                ? $query->cursorPaginate(
                    (int) ($validated['per_page'] ?? 10),
                    ['*'],
                    'cursor'
                )
                : $query->paginate((int) ($validated['per_page'] ?? 10));
            $this->preparePresentationRelations($notifications->getCollection());
            $pagination = $useCursor
                ? [
                    'per_page' => $notifications->perPage(),
                    'has_more_pages' => $notifications->hasMorePages(),
                    'next_cursor' => $notifications->nextCursor()?->encode(),
                ]
                : [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'has_more_pages' => $notifications->hasMorePages(),
                ];

            return $this->responses->success(
                StudentNotificationResource::collection($notifications),
                'تم تحميل الإشعارات',
                200,
                ['pagination' => $pagination]
            );
        } catch (\Exception $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);

            return $this->responses->error('تعذّر تحميل الإشعارات', 500);
        }
    }

    public function markAsRead(Request $request, int|string $id): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = auth('api')->user();
            if (!$user) {
                return $this->responses->error('سجّل الدخول أولًا', 401);
            }

            $notification = StudentNotification::where('id', $id)
                ->where('user_id', $user->id)
                ->first();
            if (!$notification) {
                return $this->responses->error('الإشعار غير متاح', 404);
            }

            $notification->markAsRead();
            $notification->loadMissing('notifiable');
            $this->preparePresentationRelations(collect([$notification]));

            return $this->responses->success(
                new StudentNotificationResource($notification),
                'تم فتح الإشعار'
            );
        } catch (\Exception $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);

            return $this->responses->error('تعذّر تحديث الإشعار', 500);
        }
    }

    public function show(Request $request, int|string $id): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = auth('api')->user();
            if (!$user) {
                return $this->responses->error('سجّل الدخول أولًا', 401);
            }

            $notification = StudentNotification::query()
                ->whereKey($id)
                ->where('user_id', $user->id)
                ->with('notifiable')
                ->first();
            if (!$notification) {
                return $this->responses->error('الإشعار غير متاح', 404);
            }
            $this->preparePresentationRelations(collect([$notification]));

            return $this->responses->success(
                new StudentNotificationResource($notification),
                'تم تحميل الإشعار'
            );
        } catch (\Exception $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);

            return $this->responses->error('تعذّر تحميل الإشعار', 500);
        }
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = auth('api')->user();
            if (!$user) {
                return $this->responses->error('سجّل الدخول أولًا', 401);
            }

            $updatedCount = StudentNotification::where('user_id', $user->id)
                ->unread()
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);
            $data = ['updated_count' => $updatedCount];

            return $this->responses->success(
                $data,
                'تم فتح كل الإشعارات',
                200,
                $data
            );
        } catch (\Exception $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);

            return $this->responses->error('تعذّر تحديث الإشعارات', 500);
        }
    }

    private function preparePresentationRelations(\Illuminate\Support\Collection $notifications): void
    {
        // Single-item mutation endpoints pass collect([$model]), which is a
        // base collection and has no loadMorph(). Normalize once so list and
        // single-item responses share the same bounded eager-loading path.
        $models = $notifications instanceof \Illuminate\Database\Eloquent\Collection
            ? $notifications
            : new \Illuminate\Database\Eloquent\Collection($notifications->all());
        $models->loadMorph('notifiable', [
            Course::class => ['courseSection'],
            Lesson::class => [
                'course' => fn ($query) => $query
                    ->withCount('sections')
                    ->with('courseSection'),
            ],
        ]);
        $models->loadMorphCount('notifiable', [
            Course::class => ['sections'],
        ]);
    }
}
