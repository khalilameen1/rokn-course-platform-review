<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\DesignSetting;
use App\Services\AdminUrgentTasksReadService;
use App\Services\StudentAccountStateService;
use Illuminate\Http\Request;

class UrgentTasksController extends Controller
{
    public function __construct(private readonly AdminUrgentTasksReadService $reader)
    {
    }

    public function index()
    {
        return view('admin.urgent-tasks.index', $this->reader->overview() + [
            'designSettings' => DesignSetting::getDefaultSettings(),
        ]);
    }

    public function pendingOrders(Request $request)
    {
        $validated = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $pendingOrders = $this->reader->pendingOrders((int) ($validated['page'] ?? 1))->withQueryString();

        return view('admin.urgent-tasks.pending-orders', [
            'pendingOrders' => $pendingOrders,
            'designSettings' => DesignSetting::getDefaultSettings(),
        ]);
    }

    public function inactiveStudents(Request $request)
    {
        $validated = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $data = $this->reader->inactiveStudents((int) ($validated['page'] ?? 1));
        $data['inactiveStudents']->withQueryString();

        return view('admin.urgent-tasks.inactive-students', $data + [
            'designSettings' => DesignSetting::getDefaultSettings(),
        ]);
    }

    /** Compatibility for old open pages; decisions belong to the audited order screen. */
    public function approveOrder(Request $request, Order $order)
    {
        return redirect()->route('admin.orders.show', $order)
            ->with('error', 'Use the audited order screen to approve this order.');
    }

    public function rejectOrder(Request $request, Order $order)
    {
        return redirect()->route('admin.orders.show', $order)
            ->with('error', 'Use the audited order screen to reject this order.');
    }

    /**
     * Activate a student (user).
     */
    public function activateStudent(
        Request $request,
        User $user,
        StudentAccountStateService $accounts
    )
    {
        abort_unless(strtolower((string) $user->role) === 'client', 404);
        $validated = $request->validate([
            'expected_active' => ['required', 'boolean'],
            'state_version' => ['required', 'string', 'size:64'],
        ]);
        $accounts->setActive(
            $user,
            (bool) $validated['expected_active'],
            (string) $validated['state_version'],
            true
        );

        // Redirect back to the referring page
        if (str_contains((string) $request->headers->get('referer'), 'inactive-students')) {
            return redirect()->route('admin.urgent-tasks.inactive-students')
                ->with('success', 'تم تفعيل الطالب بنجاح');
        } else {
            return redirect()->route('admin.urgent-tasks.index')
                ->with('success', 'تم تفعيل الطالب بنجاح');
        }
    }
}
