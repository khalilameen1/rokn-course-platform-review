<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ApplyAPILocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApiEnvelopeConsistencyTest extends TestCase
{
    public function test_api_controllers_never_reintroduce_boolean_http_statuses(): void
    {
        foreach (glob(app_path('Http/Controllers/API/*.php')) ?: [] as $controller) {
            $source = file_get_contents($controller);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression(
                "/['\"]status['\"]\s*=>\s*(?:true|false)\b/",
                $source,
                basename($controller).' must use the numeric HTTP status in the API envelope.'
            );
        }
    }

    public function test_manual_api_json_envelopes_keep_status_success_and_message_together(): void
    {
        foreach (glob(app_path('Http/Controllers/API/*.php')) ?: [] as $controller) {
            $source = file_get_contents($controller);
            self::assertIsString($source);
            preg_match_all(
                '/response\(\)->json\(\[(?<body>.*?)\]\s*(?:,\s*(?<http>\d+))?\s*\)/s',
                $source,
                $responses,
                PREG_SET_ORDER
            );

            foreach ($responses as $response) {
                $body = (string) ($response['body'] ?? '');
                if (preg_match("/['\"]success['\"]\s*=>/", $body) !== 1) {
                    continue;
                }

                self::assertMatchesRegularExpression(
                    "/['\"]status['\"]\s*=>/",
                    $body,
                    basename($controller).' has a manual API envelope without status.'
                );
                self::assertMatchesRegularExpression(
                    "/['\"]message['\"]\s*=>/",
                    $body,
                    basename($controller).' has a manual API envelope without message.'
                );

                if (
                    preg_match("/['\"]status['\"]\s*=>\s*(?<status>\d+)/", $body, $status) === 1
                    && isset($response['http'])
                    && $response['http'] !== ''
                ) {
                    self::assertSame(
                        (int) $status['status'],
                        (int) $response['http'],
                        basename($controller).' disagrees about payload and HTTP status.'
                    );
                }
            }
        }
    }

    public function test_inline_validation_uses_the_standard_api_error_envelope(): void
    {
        Route::post('/api/v1/_contract/validation', function (Request $request): void {
            $request->validate(['name' => ['required', 'string']]);
        });

        $response = $this->postJson('/api/v1/_contract/validation')
            ->assertUnprocessable()
            ->assertJsonPath('status', 422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['status', 'success', 'data', 'message', 'code', 'errors']);

        self::assertIsString($response->json('errors.name.0'));
        self::assertNotSame('', trim((string) $response->json('errors.name.0')));
    }

    public function test_manual_success_envelopes_receive_the_shared_server_clock(): void
    {
        Route::get('/api/v1/_contract/manual-success', static fn () => response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم',
            'data' => null,
        ]))->middleware(ApplyAPILocale::class);

        $this->getJson('/api/v1/_contract/manual-success')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonStructure(['status', 'success', 'data', 'message', 'server_time']);
    }

    public function test_etagged_envelopes_remain_byte_stable(): void
    {
        Route::get('/api/v1/_contract/cached-success', static fn () => response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم',
            'data' => ['revision' => 'fixed'],
        ])->withHeaders(['ETag' => '"fixed"']))
            ->middleware(ApplyAPILocale::class);

        $this->getJson('/api/v1/_contract/cached-success')
            ->assertOk()
            ->assertHeader('ETag', '"fixed"')
            ->assertJsonMissingPath('server_time');
    }

    public function test_missing_api_route_uses_the_same_error_envelope(): void
    {
        $this->getJson('/api/v1/_contract/does-not-exist')
            ->assertNotFound()
            ->assertJson([
                'status' => 404,
                'success' => false,
                'data' => null,
                'message' => 'المحتوى المطلوب غير متاح',
                'code' => 'not_found',
            ])
            ->assertJsonStructure(['server_time']);
    }

    public function test_unexpected_api_failure_is_normalized_without_leaking_exception_details(): void
    {
        Route::get('/api/v1/_contract/server-error', static function (): void {
            throw new \RuntimeException('private implementation detail');
        });

        $this->getJson('/api/v1/_contract/server-error')
            ->assertInternalServerError()
            ->assertJson([
                'status' => 500,
                'success' => false,
                'data' => null,
                'message' => 'تعذّر إكمال الطلب الآن',
                'code' => 'server_error',
            ])
            ->assertJsonStructure(['server_time']);
    }
}
