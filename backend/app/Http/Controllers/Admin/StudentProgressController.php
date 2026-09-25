<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminStudentProgressReadService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function statistics()
    {
        return response()->json($this->progress->statistics());
    }

    public function compare(Request $request)
    {
        $data = $request->validate([
            'user_ids' => 'required|array|min:2|max:5',
            'user_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where(fn ($users) => $users->whereRaw('LOWER(role) = ?', ['client'])),
            ],
            'course_id' => 'required|exists:courses,id',
        ]);

        return response()->json([
            'course_id' => $data['course_id'],
            'comparisons' => $this->progress->compare($data['user_ids'], (int) $data['course_id']),
        ]);
    }
}
