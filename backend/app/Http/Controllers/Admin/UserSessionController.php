<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Services\DeviceLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class UserSessionController extends Controller
{
    public function __construct(private readonly DeviceLoginService $devices) {}

    public function index(): View
    {
        $table = (new ApiToken)->getTable();
        $ready = Schema::hasColumn($table, 'session_id');
        $sessions = $ready
            ? ApiToken::query()
                ->with('user:id,name,email')
                ->whereHasNotExpired()
                ->whereNotNull('session_id')
                ->orderByDesc('last_used_at')
                ->orderByDesc('issued_at')
                ->orderByDesc('session_id')
                ->paginate(50)
            : null;

        $platforms = $ready
            ? ApiToken::query()
                ->whereHasNotExpired()
                ->selectRaw("COALESCE(platform, 'other') AS platform, COUNT(*) AS total")
                ->groupBy('platform')
                ->pluck('total', 'platform')
            : collect();

        return view('admin.user_sessions.index', compact('sessions', 'platforms', 'ready'));
    }

    public function destroy(string $sessionId): RedirectResponse
    {
        $this->devices->revokeSessionFromDashboard($sessionId);

        return back()->with('success', 'تم إنهاء جلسة الجهاز.');
    }
}
