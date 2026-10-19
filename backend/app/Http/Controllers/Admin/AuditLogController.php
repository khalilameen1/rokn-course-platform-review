<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\AuditLogResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogIndexRequest;
use Illuminate\Http\RedirectResponse;

final class AuditLogController extends Controller
{
    public function index(AuditLogIndexRequest $request): RedirectResponse
    {
        $filters = $request->validated();

        return redirect(AuditLogResource::getUrl('index', ['filters' => ['criteria' => $filters]]));
    }
}
