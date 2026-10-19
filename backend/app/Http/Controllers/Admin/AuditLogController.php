<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogIndexRequest;
use App\Services\AdminAuditLogReadService;
use Illuminate\View\View;

final class AuditLogController extends Controller
{
    public function index(AuditLogIndexRequest $request, AdminAuditLogReadService $audit): View
    {
        $filters = $request->validated();

        return view('admin.audit-log.index', [
            'entries' => $audit->paginate($filters),
            'actors' => $audit->actors(),
            'filters' => $filters,
            'recordsChanges' => $audit->recordsChanges(),
        ]);
    }
}
