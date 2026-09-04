<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClassificationResource;
use App\Services\ApiResponseService;
use App\Services\CourseCatalogueQueryService;
use Illuminate\Http\JsonResponse;

final class ClassificationController extends Controller
{
    public function index(
        CourseCatalogueQueryService $catalogue,
        ApiResponseService $responses
    ): JsonResponse
    {
        $revision = $catalogue->revision();

        return $responses->success(
            ClassificationResource::collection($catalogue->publicClassifications()),
            'تم تحميل التصنيفات',
            200,
            ['catalogue_revision' => $revision]
        );
    }
}
