<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourseModuleOrderRequest;
use App\Http\Requests\Admin\CourseModuleRequest;
use App\Http\Requests\Admin\CourseModuleVersionRequest;
use App\Models\Course;
use App\Models\CourseModule;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminCourseModuleApplicationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CourseModuleController extends Controller
{
    public function __construct(
        private readonly AdminCourseModuleApplicationService $modules,
        private readonly AdminAuthoringCreateIntentService $createIntents
    ) {
    }

    public function create(Course $course): Response
    {
        return $this->authoringRedirect($course);
    }

    public function store(CourseModuleRequest $request, Course $course): Response
    {
        $data = $request->validated();
        $payload = $this->modules->store(
            $course,
            $data,
            (int) $data['authoring_version'],
            function (Course $lockedCourse, CourseModule $module, array $result) use ($request): void {
                $this->completeStoreIntent($request, $lockedCourse, $module, $result);
            }
        );

        return $this->mutationResponse($request, $course, $payload, 'تم إضافة الوحدة بنجاح');
    }

    public function edit(Course $course, CourseModule $module): Response
    {
        $this->assertModuleBelongsToCourse($course, $module);

        return $this->authoringRedirect($course);
    }

    public function update(
        CourseModuleRequest $request,
        Course $course,
        CourseModule $module
    ): Response {
        $data = $request->validated();
        $payload = $this->modules->update(
            $course,
            $module,
            $data,
            (int) $data['authoring_version']
        );

        return $this->mutationResponse($request, $course, $payload, 'تم تحديث الوحدة بنجاح');
    }

    public function destroy(
        CourseModuleVersionRequest $request,
        Course $course,
        CourseModule $module
    ): Response {
        $payload = $this->modules->destroy(
            $course,
            $module,
            (int) $request->validated('authoring_version')
        );

        return $this->mutationResponse($request, $course, $payload, 'تم حذف الوحدة بنجاح');
    }

    public function reorder(CourseModuleOrderRequest $request, Course $course): Response
    {
        $data = $request->validated();
        $modules = array_map(
            static fn (array $module): array => [
                'id' => (int) $module['id'],
                'order' => (int) $module['order'],
            ],
            $data['modules']
        );

        return response()->json($this->modules->reorder(
            $course,
            $modules,
            (int) $data['authoring_version']
        ));
    }

    /** @param array<string, mixed> $payload */
    private function mutationResponse(
        Request $request,
        Course $course,
        array $payload,
        string $htmlMessage
    ): Response {
        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return $this->authoringRedirect($course)->with('success', $htmlMessage);
    }

    /** @param array<string, mixed> $payload */
    private function completeStoreIntent(
        Request $request,
        Course $course,
        CourseModule $module,
        array $payload
    ): void {
        if ($request->expectsJson()) {
            $this->createIntents->completeJson(
                $request,
                $payload,
                200,
                CourseModule::class,
                $module->id
            );
            return;
        }

        $this->createIntents->completeRedirect(
            $request,
            $this->authoringLocation($course),
            302,
            CourseModule::class,
            $module->id
        );
    }

    private function assertModuleBelongsToCourse(Course $course, CourseModule $module): void
    {
        abort_unless((int) $module->course_id === (int) $course->id, 404);
    }

    private function authoringRedirect(Course $course): Response
    {
        return redirect()->to($this->authoringLocation($course));
    }

    private function authoringLocation(Course $course): string
    {
        return route('admin.courses.show', $course);
    }
}
