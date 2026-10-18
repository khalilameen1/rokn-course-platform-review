<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\PortfolioProfileService;
use App\Services\SafeExternalUrl;
use App\Support\UnicodeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PortfolioProfileController extends Controller
{
    public function __construct(
        private readonly PortfolioProfileService $profiles,
        private readonly ApiResponseService $responses
    ) {}

    public function show(): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        return $this->responses->success($this->profiles->profile($user), 'تم تحميل بيانات المعرض');
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        foreach (['portfolio_headline'] as $field) {
            if ($request->has($field) && is_string($request->input($field))) {
                $request->merge([$field => UnicodeText::clean($request->input($field), false)]);
            }
        }
        if (is_array($request->input('portfolio_skills'))) {
            $request->merge([
                'portfolio_skills' => array_map(
                    static fn ($skill) => is_string($skill)
                        ? UnicodeText::clean($skill, false)
                        : $skill,
                    $request->input('portfolio_skills')
                ),
            ]);
        }
        if (is_array($request->input('portfolio_links'))) {
            $request->merge([
                'portfolio_links' => array_map(static function ($link): array {
                    if (! is_array($link)) {
                        return [];
                    }
                    if (isset($link['label']) && is_string($link['label'])) {
                        $link['label'] = UnicodeText::clean($link['label'], false);
                    }

                    return $link;
                }, $request->input('portfolio_links')),
            ]);
        }
        $validated = $request->validate([
            'portfolio_headline' => 'nullable|string|max:160',
            'portfolio_skills' => 'nullable|array|max:30',
            'portfolio_skills.*' => 'string|max:80',
            'portfolio_links' => 'nullable|array|max:10',
            'portfolio_links.*.label' => 'required_with:portfolio_links|string|max:40',
            'portfolio_links.*.url' => [
                'required_with:portfolio_links',
                'string',
                'max:2000',
                SafeExternalUrl::validationRule(),
            ],
        ]);

        return $this->responses->success($this->profiles->update($user, $validated), 'تم تحديث بيانات المعرض');
    }
}
