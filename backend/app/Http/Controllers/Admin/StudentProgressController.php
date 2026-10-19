<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StudentProgressIndexRequest;
use App\Services\AdminStudentProgressReadService;

class StudentProgressController extends Controller
{
    public function __construct(private readonly AdminStudentProgressReadService $progress) {}

    public function index(StudentProgressIndexRequest $request)
    {
        $filters = $request->validated();

        return view('admin.student-progress.index', $this->progress->listing($filters, $request->query()));
    }

    public function show($userId)
    {
        return view('admin.student-progress.show', $this->progress->workspace((int) $userId));
    }
}
