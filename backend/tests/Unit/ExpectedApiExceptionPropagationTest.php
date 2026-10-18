<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;
use Throwable;

final class ExpectedApiExceptionPropagationTest extends TestCase
{
    public function test_expected_request_exceptions_are_not_misreported_as_server_faults(): void
    {
        $controller = new class extends Controller
        {
            public function bubble(Throwable $exception): void
            {
                $this->rethrowExpectedRequestException($exception);
            }
        };

        foreach ([new ModelNotFoundException, new NotFoundHttpException] as $exception) {
            try {
                $controller->bubble($exception);
                self::fail('Expected request exception was swallowed.');
            } catch (Throwable $thrown) {
                self::assertSame($exception, $thrown);
            }
        }
    }

    public function test_unexpected_faults_remain_available_for_contextual_controller_fallbacks(): void
    {
        $controller = new class extends Controller
        {
            public function bubble(Throwable $exception): void
            {
                $this->rethrowExpectedRequestException($exception);
            }
        };

        $controller->bubble(new RuntimeException('infrastructure fault'));

        self::assertTrue(true);
    }
}
