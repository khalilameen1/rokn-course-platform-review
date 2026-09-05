<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\User;
use App\Services\BunnyDirectUploadService;
use App\Support\UnicodeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CourseSectionVideoUploadController extends Controller
{
    public function store(
        Request $request,
        Course $course,
        BunnyDirectUploadService $uploads
    ): JsonResponse {
        try {
            $request->merge([
                'title' => UnicodeText::clean($request->input('title'), false),
            ]);
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'size' => 'required|integer|min:1|max:' . BunnyDirectUploadService::MAX_BYTES,
                'mime' => ['required', 'string', Rule::in(BunnyDirectUploadService::MIMES)],
                'original_name' => ['required', 'string', 'max:255'],
                'idempotency_key' => ['required', 'uuid'],
                'authoring_version' => ['required', 'integer', 'min:1'],
                'section_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('course_sections', 'id')->where(
                        fn ($query) => $query->where('course_id', $course->id)->whereNull('deleted_at')
                    ),
                ],
            ]);
            /** @var User $admin */
            $admin = $request->user();
            $section = !empty($validated['section_id'])
                ? CourseSection::query()->where('course_id', $course->id)->findOrFail($validated['section_id'])
                : null;

            return response()->json([
                'success' => true,
                'data' => $uploads->issue(
                    $course,
                    $admin,
                    (string) $validated['title'],
                    (int) $validated['size'],
                    (string) $validated['mime'],
                    (string) $validated['original_name'],
                    (string) $validated['idempotency_key'],
                    $section,
                    (int) $validated['authoring_version']
                ),
            ]);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (array_key_exists('bunny_upload_allocation_in_progress', $errors)) {
                unset($errors['bunny_upload_allocation_in_progress']);
                $message = collect($errors)->flatten()->first(
                    fn ($value) => is_string($value) && trim($value) !== ''
                );

                return response()->json([
                    'success' => false,
                    'code' => 'bunny_upload_allocation_in_progress',
                    'message' => $message ?: "ما زال تجهيز الرفع جاريًا\nحاول بعد لحظات",
                    'errors' => $errors,
                ], 409);
            }

            return $this->terminalUploadResponse($exception, 'bunny_upload_operation_terminal', 'bunny_upload_operation_unavailable');
        }
    }

    public function renew(
        Request $request,
        Course $course,
        BunnyDirectUploadService $uploads
    ): JsonResponse {
        try {
            $validated = $request->validate(['claim' => 'required|string|max:4096']);
            /** @var User $admin */
            $admin = $request->user();

            return response()->json([
                'success' => true,
                'data' => $uploads->authorization($course, $admin, (string) $validated['claim']),
            ]);
        } catch (ValidationException $exception) {
            return $this->terminalUploadResponse($exception, 'bunny_video_claim_terminal', 'bunny_upload_claim_unavailable');
        }
    }

    private function terminalUploadResponse(
        ValidationException $exception,
        string $terminalField,
        string $terminalCode
    ): JsonResponse {
        $errors = $exception->errors();
        if (!array_key_exists($terminalField, $errors)) {
            throw $exception;
        }

        unset($errors[$terminalField]);
        $message = collect($errors)->flatten()->first(fn ($value) => is_string($value) && trim($value) !== '');

        return response()->json([
            'success' => false,
            'code' => $terminalCode,
            'message' => $message ?: 'انتهت صلاحية عملية الرفع',
            'errors' => $errors,
        ], 410);
    }
}
