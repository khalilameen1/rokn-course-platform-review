<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\ManagedPublicContentService;
use App\Services\PublicAppSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use App\Support\RoknLocale;

final class PublicContentController extends Controller
{
    private const PAGES = [
        'about' => 'about',
        'privacy' => 'privacy',
        'terms' => 'terms',
        'returns' => 'returns',
        'contact' => 'contact',
    ];

    public function show(
        Request $request,
        string $page,
        ApiResponseService $responses,
        ManagedPublicContentService $managedContent,
        PublicAppSettingsService $publicSettings
    ): JsonResponse {
        abort_unless(array_key_exists($page, self::PAGES), 404);

        $locale = RoknLocale::fromRequest($request);
        $translation = Lang::get(self::PAGES[$page], [], $locale);
        abort_unless(is_array($translation), 404);

        $payload = [
            'slug' => $page,
            'locale' => $locale,
            'title' => (string) ($translation['title'] ?? $translation['heading'] ?? ''),
            'heading' => (string) ($translation['heading'] ?? $translation['title'] ?? ''),
            'content' => $translation,
            'web_url' => $this->webUrl($page),
        ];

        $managedBody = $managedContent->body($page, $locale);
        $payload['managed_body'] = $managedBody;
        $payload['source'] = $managedBody !== null ? 'dashboard' : 'application';

        if ($page === 'contact') {
            $settings = $publicSettings->snapshot($locale);
            $payload['contact'] = [
                'email' => $settings['support_contacts']['email'] ?? null,
                'phone' => $settings['support_contacts']['phone'] ?? null,
                'whatsapp' => $settings['support_contacts']['whatsapp'] ?? null,
                'form' => [
                    'method' => 'POST',
                    'endpoint' => '/api/v1/contact',
                    'required_fields' => ['name', 'email', 'message'],
                    'optional_fields' => ['phone'],
                ],
            ];
        }

        // Hash the representation the client actually receives. Translation
        // updates and changed support contacts are content changes too; a hash
        // of only the optional dashboard body produced false 304/stale pages.
        $payload['revision'] = hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return $responses->success($payload, 'تم تحميل الصفحة')->withHeaders([
            'Cache-Control' => 'public, max-age=60, stale-if-error=300',
            'ETag' => '"'.$payload['revision'].'"',
            'Vary' => 'Accept-Language',
        ]);
    }

    private function webUrl(string $page): string
    {
        return match ($page) {
            'about' => route('about'),
            'privacy' => route('privacy'),
            'terms' => route('terms'),
            'returns' => route('returns-policy'),
            'contact' => route('contact'),
        };
    }
}
