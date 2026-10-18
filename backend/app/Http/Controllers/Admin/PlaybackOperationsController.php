<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlaybackSession;
use App\Services\PlaybackOperationsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlaybackOperationsController extends Controller
{
    public function index(PlaybackOperationsService $operations): View
    {
        return view('admin.playback_operations', [
            'operations' => $operations->snapshot(50),
        ]);
    }

    public function terminateStale(
        PlaybackSession $playbackSession,
        PlaybackOperationsService $operations
    ): RedirectResponse {
        $result = DB::transaction(function () use ($playbackSession, $operations): string {
            /** @var PlaybackSession|null $locked */
            $locked = PlaybackSession::query()
                ->lockForUpdate()
                ->find($playbackSession->getKey());
            if (! $locked) {
                return 'missing';
            }
            if ($locked->ended_at) {
                return 'already_ended';
            }
            if (! $operations->isStale($locked)) {
                return 'still_active';
            }

            // Keep admin terminations in the same rollup as player stop events.
            $locked->forceFill([
                'ended_at' => now(),
                'event_type' => 'stop',
                'end_reason' => 'admin_stale_termination',
            ])->save();

            return 'ended';
        }, 3);

        return match ($result) {
            'ended' => back()->with('success', 'تم إنهاء الجلسة العالقة وتسجيل العملية.'),
            'already_ended' => back()->with('info', 'الجلسة منتهية بالفعل.'),
            'still_active' => back()->with('warning', 'الجلسة أرسلت نشاطًا حديثًا، لذلك لم يتم إنهاؤها.'),
            default => back()->with('warning', 'لم تعد الجلسة موجودة.'),
        };
    }
}
