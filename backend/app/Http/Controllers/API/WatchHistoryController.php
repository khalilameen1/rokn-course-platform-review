<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WatchingLog;
use App\Services\ApiResponseService;
use App\Services\BunnyDeliveryService;
use App\Services\PlaybackCapabilityService;
use App\Services\WatchHistoryCanonicalizationService;
use App\Services\WatchHistoryRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WatchHistoryController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly BunnyDeliveryService $delivery,
        private readonly WatchHistoryCanonicalizationService $canonicalHistory
    ) {}

    /**
     * Return a bounded, resume-oriented history. Video URLs are deliberately
     * excluded so this endpoint cannot bypass the normal lesson access path.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
            'course_id' => 'nullable|integer|exists:courses,id',
        ]);

        $user = auth('api')->user();
        $this->canonicalHistory->ensureCanonical((int) $user->id);
        $history = WatchingLog::query()
            ->where('user_id', $user->id)
            ->whereHas('course', function ($courses): void {
                $courses->where('is_coming_soon', false)
                    ->whereHas('sections');
            })
            ->whereHas('lesson.courseSection', function ($sections): void {
                $sections->whereColumn('course_sections.course_id', 'watching_logs.course_id');
            })
            ->when(isset($validated['course_id']), function ($query) use ($validated) {
                $query->where('course_id', $validated['course_id']);
            })
            ->with([
                'lesson:id,list_id,title,title_ar,title_en,thumbnail_path,duration_minutes',
                'lesson.mediaState:id,lesson_id,duration_seconds',
                'course:id,name_ar,name_en,image',
                // The image accessor reads the featured photo; load it once
                // per page as LearningDashboardService does, not per row.
                'course.photo',
                'courseSection:id,course_id,module_id,sectionable_type,sectionable_id,order,title,title_ar,title_en',
            ])
            // Every write path sets watched_at, so recency is an indexed
            // column: (user_id[, course_id], watched_at) + the InnoDB primary
            // key suffix serves this order and stops at the page boundary.
            ->orderByDesc('watching_logs.watched_at')
            ->orderByDesc('watching_logs.id')
            // The app reads the first page only and never a total. A
            // simple page avoids a COUNT over the nested visibility EXISTS.
            ->simplePaginate((int) ($validated['per_page'] ?? 20));

        return $this->responses->success([
            'tracking_enabled' => (bool) $user->watch_history_enabled,
            'items' => collect($history->items())->map(function (WatchingLog $log) {
                $providerDuration = max(
                    0,
                    (int) ($log->lesson?->mediaState?->duration_seconds ?? 0)
                );
                $duration = $providerDuration > 0
                    ? $providerDuration
                    : max(0, (int) ($log->duration_seconds ?? 0));
                $progress = $duration && $duration > 0
                    ? min(100, round(($log->position_seconds / $duration) * 100, 2))
                    : null;
                $thumbnailPath = trim((string) $log->lesson?->thumbnail_path);
                $thumbnail = $thumbnailPath !== ''
                    ? $this->delivery->storageUrl($thumbnailPath)
                    : null;

                return [
                    'id' => $log->id,
                    'course_id' => $log->course_id,
                    'course_title' => $log->course?->name_ar ?? $log->course_name,
                    'course_title_en' => $log->course?->name_en,
                    'course_image' => $log->course?->image,
                    'lesson_id' => $log->lesson_id,
                    'lesson_title' => $log->lesson?->title ?? $log->lesson_name,
                    'lesson_thumbnail' => $thumbnail ?: $log->course?->image,
                    'course_section_id' => $log->course_section_id,
                    'section_order' => $log->courseSection?->order,
                    'position_seconds' => $log->position_seconds,
                    'duration_seconds' => $duration,
                    'progress_percentage' => $progress,
                    'is_completed' => $log->completed_at !== null,
                    'watched_at' => $log->watched_at ?? $log->updated_at,
                ];
            })->values(),
            'pagination' => [
                'current_page' => $history->currentPage(),
                'per_page' => $history->perPage(),
                'has_more_pages' => $history->hasMorePages(),
            ],
        ], 'تم تحميل سجل المشاهدة');
    }

    /** Record a verified playback sample and advance durable learning state. */
    public function store(Request $request, WatchHistoryRecorder $recorder): JsonResponse
    {
        $validated = $request->validate([
            // Existence is answered by the recorder's lesson load; a separate
            // exists:lessons probe was a second read of the same row.
            'lesson_id' => 'required|integer',
            'position_seconds' => 'nullable|integer|min:0|max:86400',
            'duration_seconds' => 'nullable|integer|min:1|max:86400',
            'is_completed' => 'nullable|boolean',
            'playback_session_id' => 'required|uuid',
            'sequence' => 'required|integer|min:1|max:2147483647',
            'event_type' => 'nullable|string|in:play,start,heartbeat,pause,background,stop,complete,error',
            'end_reason' => 'nullable|string|in:user_exit,navigation,lesson_changed,'
                .'app_closed,playback_error,source_expired,replaced,unknown',
            'is_terminal' => 'nullable|boolean',
            'effective_quality' => 'nullable|string|in:auto,1080p,720p,480p,360p',
            'effective_bitrate_kbps' => 'nullable|integer|min:0|max:100000',
            'playback_rate' => 'nullable|numeric|min:0.5|max:2',
            'recovery_count' => 'nullable|integer|min:0|max:20',
            'startup_latency_ms' => 'nullable|integer|min:0|max:120000',
            'buffer_count' => 'nullable|integer|min:0|max:500',
            'buffer_duration_ms' => 'nullable|integer|min:0|max:3600000',
            'error_code' => 'nullable|string|max:64',
            'diagnostics' => 'nullable|array|max:12',
        ] + PlaybackCapabilityService::validationRules());

        $result = $recorder->record(auth('api')->user(), $validated);

        return match ($result['state']) {
            WatchHistoryRecorder::REVISION_CHANGED => $this->responses->error(
                "تم تحديث الكورس\nنعيد تحميل أحدث نسخة",
                409,
                $result['data'],
                ['code' => 'course_revision_changed']
            ),
            WatchHistoryRecorder::LESSON_UNAVAILABLE => $this->responses->error('المقطع غير متاح', 404),
            WatchHistoryRecorder::ACCESS_REQUIRED => $this->responses->error('افتح الكورس أولًا لحفظ تقدمك', 403),
            WatchHistoryRecorder::VIDEO_METADATA_UNAVAILABLE => $this->responses->error(
                "بيانات الفيديو قيد التجهيز\nحاول بعد قليل",
                409,
                null,
                ['code' => 'video_metadata_unavailable']
            ),
            WatchHistoryRecorder::INVALID_SESSION => $this->responses->error(
                'تعذّر حفظ تقدم هذا المقطع',
                422,
                $result['data']
            ),
            WatchHistoryRecorder::PREVIEW => $this->responses->success($result['data'], 'تم حفظ حالة التشغيل'),
            WatchHistoryRecorder::UNTRACKED => $this->responses->success($result['data'], 'تم حفظ تقدمك'),
            default => $this->responses->success($result['data'], 'تم حفظ موضع المشاهدة'),
        };
    }
}
