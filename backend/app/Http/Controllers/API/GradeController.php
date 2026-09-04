<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\GradeResource;
use App\Http\Resources\BaseCourseResource;
use App\Models\Grade;
use App\Services\ApiResponseService;
use App\Services\CourseCatalogueQueryService;
use App\Services\CourseDurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GradeController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly CourseCatalogueQueryService $catalogue,
        private readonly CourseDurationService $duration
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $grades = Grade::active()
                ->ordered()
                ->withCount([
                    'courses' => fn ($courses) => $this->catalogue->constrainPublic($courses),
                ])
                ->get();

            return $this->responses->success(
                GradeResource::collection($grades),
                'تم تحميل المراحل'
            );
        } catch (\Exception $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);

            return $this->responses->error(
                'Failed to fetch grades',
                500,
                null,
                ['error' => 'تعذّر تحميل المستويات']
            );
        }
    }

    public function show(Grade $grade): JsonResponse
    {
        try {
            return $this->responses->success(
                new GradeResource($grade->loadCount([
                    'courses' => fn ($courses) => $this->catalogue->constrainPublic($courses),
                ])),
                'تم تحميل المرحلة'
            );
        } catch (\Exception $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);

            return $this->responses->error(
                'Failed to fetch grade',
                500,
                null,
                ['error' => 'تعذّر تحميل المستوى']
            );
        }
    }

    public function courses(Grade $grade): JsonResponse
    {
        try {
            $courses = $this->catalogue->orderForDiscovery(
                $this->catalogue->applyPublicContract($grade->courses()->getQuery())
            )->get();
            $this->duration->attachMany($courses);

            return $this->responses->success([
                'grade' => new GradeResource($grade),
                'courses' => BaseCourseResource::collection($courses),
            ], 'تم تحميل كورسات المرحلة');
        } catch (\Exception $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);

            return $this->responses->error(
                'Failed to fetch grade courses',
                500,
                null,
                ['error' => 'تعذّر تحميل كورسات المستوى']
            );
        }
    }
}
