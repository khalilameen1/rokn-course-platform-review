<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAuditLogReadService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AuditLogController extends Controller
{
    public function index(Request $request, AdminAuditLogReadService $audit): View
    {
        $filters = $request->validate([
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return view('admin.audit-log.index', [
            'entries' => $audit->paginate($filters),
            'actors' => $audit->actors(),
            'filters' => $filters,
            'recordsChanges' => $audit->recordsChanges(),
        ]);
    }
}
