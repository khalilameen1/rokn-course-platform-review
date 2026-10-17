<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminStudentProgressReadService;
use Illuminate\Http\Request;

class StudentProgressController extends Controller
{
    public function __construct(private readonly AdminStudentProgressReadService $progress)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:120',
            'course_id' => 'nullable|integer|exists:courses,id',
        ]);

        return view('admin.student-progress.index', $this->progress->listing($filters, $request->query()));
    }

    public function show($userId)
    {
        return view('admin.student-progress.show', $this->progress->workspace((int) $userId));
    }
}
