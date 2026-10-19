<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\StudentProgressResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StudentProgressIndexRequest;
use Illuminate\Http\RedirectResponse;

class StudentProgressController extends Controller
{
    public function index(StudentProgressIndexRequest $request): RedirectResponse
    {
        return redirect()->to(StudentProgressResource::listUrl($request->validated()));
    }

    public function show(int|string $userId): RedirectResponse
    {
        return redirect()->to(StudentProgressResource::getUrl('view', ['record' => $userId]));
    }
}
