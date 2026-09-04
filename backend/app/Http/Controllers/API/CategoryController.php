<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;

final class CategoryController extends Controller
{
    public function __construct(private readonly ApiResponseService $responses)
    {
    }

    public function index(): JsonResponse
    {
        return $this->responses->success(
            CategoryResource::collection(
                Category::query()
                    ->orderBy('name_ar')
                    ->orderBy('id')
                    ->get()
            ),
            'تم تحميل التصنيفات',
            200,
            [
                'deprecated' => true,
                'scope' => 'legacy_categories',
                'course_classifications_endpoint' => '/api/classifications',
            ]
        );
    }
}
