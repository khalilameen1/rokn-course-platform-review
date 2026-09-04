<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PortfolioShareIdentityService;
use App\Services\SafeExternalUrl;
use App\Support\RoknPublicUrl;
use App\Support\UnicodeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PortfolioProfileController extends Controller
{
    public function __construct(private PortfolioShareIdentityService $portfolioShares)
    {
    }

    public function show(): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل بيانات المعرض',
            'data' => $this->profilePayload($user),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        foreach (['portfolio_headline', 'portfolio_location'] as $field) {
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
                    if (!is_array($link)) {
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
            'portfolio_location' => 'nullable|string|max:120',
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

        $user = DB::transaction(function () use ($user, $validated): User {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->update($validated);

            return $locked->fresh();
        });

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحديث بيانات المعرض',
            'data' => $this->profilePayload($user->fresh()),
        ]);
    }

    private function profilePayload(User $user): array
    {
        $slug = $this->portfolioShares->ensure($user);

        return [
            'slug' => $slug,
            'share_mode' => 'unlisted',
            'headline' => $user->portfolio_headline,
            'location' => $user->portfolio_location,
            'skills' => $user->portfolio_skills ?? [],
            'links' => collect($user->portfolio_links ?? [])
                ->map(function ($link): ?array {
                    $safeUrl = SafeExternalUrl::sanitize($link['url'] ?? null);
                    if (!$safeUrl) {
                        return null;
                    }

                    return [
                        'label' => (string) ($link['label'] ?? ''),
                        'url' => $safeUrl,
                    ];
                })
                ->filter()
                ->values()
                ->all(),
            'public_url' => RoknPublicUrl::portfolio($slug),
        ];
    }
}
