<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Course;
use App\Services\MediaReconciliationService;
use Illuminate\Http\RedirectResponse;

class MediaHealthController extends Controller
{
    public function probe(
        Lesson $lesson,
        MediaReconciliationService $reconciliation
    ): RedirectResponse
    {
        $result = $reconciliation->reconcileLesson($lesson, true, true);
        $playable = ($result['playback_status'] ?? null) === 'ready';
        $healthy = ($result['integrity_status'] ?? null) === 'healthy';

        return back()->with(
            $playable && $healthy ? 'success' : 'warning',
            $playable
                ? ($healthy ? 'الفيديو جاهز للتشغيل' : 'الفيديو يعمل لكن توجد تفاصيل تحتاج مراجعة')
                : 'الفيديو لم يجهز للمشاهدة بعد'
        );
    }

    public function probeCourse(
        Course $course,
        MediaReconciliationService $reconciliation
    ): RedirectResponse {
        $result = $reconciliation->reconcileCourse($course, true, true);
        $unavailable = (int) ($result['counts']['quarantined'] ?? 0);
        $attention = (int) ($result['counts']['attention'] ?? 0);

        if ($unavailable > 0) {
            return back()->with('warning', "تعذر تشغيل {$unavailable} من فيديوهات الكورس");
        }
        if ($attention > 0) {
            return back()->with('warning', 'الفيديوهات تعمل لكن توجد تفاصيل تحتاج مراجعة');
        }

        return back()->with('success', 'تم تشغيل وفحص فيديوهات الكورس');
    }
}
