<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ApplyAPILocale;
use App\Services\ApiResponseService;
use App\Support\ApiErrorContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ApplyApiLocaleEnvelopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 09:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_complete_success_envelope_passes_through_byte_identical(): void
    {
        $response = app(ApiResponseService::class)->success(
            ['items' => [['id' => 1, 'title' => 'درس', 'empty' => new \stdClass()]]],
            'تم'
        );
        $before = $response->getContent();

        $handled = $this->handle($response);

        self::assertSame($before, $handled->getContent());
        self::assertSame('ar', $handled->headers->get('Content-Language'));
        self::assertStringContainsString('Accept-Language', (string) $handled->headers->get('Vary'));
    }

    public function test_manual_success_receives_server_time_with_unchanged_fields(): void
    {
        $handled = $this->handle(response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم',
            'data' => ['id' => 7],
        ]));

        self::assertSame([
            'status' => 200,
            'success' => true,
            'message' => 'تم',
            'data' => ['id' => 7],
            'server_time' => '2026-10-17T09:30:00+00:00',
        ], $handled->getData(true));
    }

    public function test_manual_error_is_normalized_in_the_existing_key_order(): void
    {
        $handled = $this->handle(response()->json(['message' => '  ', 'errors' => ['x' => ['bad']]], 422));

        self::assertSame(422, $handled->getStatusCode());
        self::assertSame([
            'message' => 'تعذّر إكمال الطلب',
            'errors' => ['x' => ['bad']],
            'status' => 422,
            'http_status' => 422,
            'success' => false,
            'data' => null,
            'code' => ApiErrorContract::codeForStatus(422),
            'retryable' => ApiErrorContract::retryable(422),
            'server_time' => '2026-10-17T09:30:00+00:00',
        ], $handled->getData(true));
    }

    public function test_error_envelope_with_server_time_keeps_its_clock_and_explicit_fields(): void
    {
        $response = app(ApiResponseService::class)->error('مرفوض', 409, null, [
            'code' => 'conflict_custom',
            'retryable' => true,
        ]);
        $expected = $response->getData(true);

        self::assertSame($expected, $this->handle($response)->getData(true));
    }

    public function test_list_payloads_keep_the_existing_object_conversion(): void
    {
        $handled = $this->handle(response()->json(['a', 'b']));

        self::assertSame(
            '{"0":"a","1":"b","server_time":"2026-10-17T09:30:00+00:00"}',
            $handled->getContent()
        );
    }

    public function test_etagged_responses_are_untouched(): void
    {
        $response = response()->json(['data' => ['revision' => 'fixed']])->withHeaders(['ETag' => '"fixed"']);
        $before = $response->getContent();

        self::assertSame($before, $this->handle($response)->getContent());
    }

    private function handle(JsonResponse $response): JsonResponse
    {
        $request = Request::create('/api/v1/_contract', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'ar']);
        $handled = (new ApplyAPILocale())->handle($request, static fn () => $response);
        self::assertInstanceOf(JsonResponse::class, $handled);

        return $handled;
    }
}
