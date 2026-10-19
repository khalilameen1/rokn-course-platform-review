<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filament\Pages\PlaybackOperations;
use App\Http\Controllers\Controller;
use App\Models\PlaybackSession;
use App\Services\PlaybackOperationsService;
use Illuminate\Http\RedirectResponse;

class PlaybackOperationsController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect(PlaybackOperations::getUrl());
    }

    public function terminateStale(
        PlaybackSession $playbackSession,
        PlaybackOperationsService $operations
    ): RedirectResponse {
        return match ($operations->terminateStale((string) $playbackSession->getKey())) {
            'ended' => back()->with('success', 'تم إنهاء الجلسة العالقة وتسجيل العملية.'),
            'already_ended' => back()->with('info', 'الجلسة منتهية بالفعل.'),
            'still_active' => back()->with('warning', 'الجلسة أرسلت نشاطًا حديثًا، لذلك لم يتم إنهاؤها.'),
            default => back()->with('warning', 'لم تعد الجلسة موجودة.'),
        };
    }
}
