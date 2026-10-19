<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filament\Pages\DeviceSessions;
use App\Http\Controllers\Controller;
use App\Services\DeviceLoginService;
use Illuminate\Http\RedirectResponse;

class UserSessionController extends Controller
{
    public function __construct(private readonly DeviceLoginService $devices) {}

    public function index(): RedirectResponse
    {
        return redirect(DeviceSessions::getUrl());
    }

    public function destroy(string $sessionId): RedirectResponse
    {
        $this->devices->revokeSessionFromDashboard($sessionId);

        return back()->with('success', 'تم إنهاء جلسة الجهاز.');
    }
}
