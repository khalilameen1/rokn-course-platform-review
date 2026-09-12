<?php

namespace App\Exceptions;

use App\Services\ApiResponseService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Sentry\Laravel\Integration;
use Throwable;

class Handler extends ExceptionHandler
{
    public function register(): void
    {
        $this->reportable(static function (Throwable $exception): void {
            Integration::captureUnhandledException($exception);
        });
    }

    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        'access_token',
        'refresh_token',
        'authorization_code',
        'nonce',
        'id_token',
        'api_token',
        'device_token',
        'purchase_token',
        'client_secret',
        'secret',
        'secret_key',
        'api_key',
        'signature',
        'card_number',
        'cardholder_name',
        'cvv',
        'cvc',
    ];

    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \Exception
     */
    public function report(Throwable $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $exception)
    {
        if ($request->is('api/*')) {
            $responses = app(ApiResponseService::class);

            if ($exception instanceof AuthenticationException) {
                return $this->withRequestIdentity($request, $responses->error(
                    'سجّل الدخول أولًا',
                    401,
                    null,
                    ['code' => 'unauthenticated']
                ));
            }

            if ($exception instanceof ValidationException) {
                return $this->withRequestIdentity($request, $responses->error(
                    'راجع البيانات ثم حاول مرة أخرى',
                    422,
                    null,
                    [
                        'code' => 'validation_failed',
                        'errors' => $exception->errors(),
                    ]
                ));
            }

            if ($exception instanceof AuthorizationException) {
                return $this->withRequestIdentity($request, $responses->error(
                    'هذا الإجراء غير متاح لحسابك',
                    403,
                    null,
                    ['code' => 'forbidden']
                ));
            }

            if ($exception instanceof ModelNotFoundException) {
                return $this->withRequestIdentity($request, $responses->error(
                    'المحتوى المطلوب غير متاح',
                    404,
                    null,
                    ['code' => 'not_found']
                ));
            }

            if ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                [$message, $code] = match ($status) {
                    401 => ['سجّل الدخول أولًا', 'unauthenticated'],
                    403 => ['هذا الإجراء غير متاح لحسابك', 'forbidden'],
                    404, 410 => ['المحتوى المطلوب غير متاح', 'not_found'],
                    405 => ['تعذّر إكمال الطلب', 'method_not_allowed'],
                    408 => ['انتهت مهلة الطلب', 'request_timeout'],
                    409 => ['تغيّرت البيانات أعد تحميلها ثم حاول مرة أخرى', 'conflict'],
                    413 => ['حجم الملف أكبر من المسموح', 'payload_too_large'],
                    422 => ['راجع البيانات ثم حاول مرة أخرى', 'validation_failed'],
                    429 => ['انتظر قليلًا ثم حاول مرة أخرى', 'rate_limited'],
                    503 => ['الخدمة غير متاحة للحظات', 'service_unavailable'],
                    default => $status >= 500
                        ? ['تعذّر إكمال الطلب الآن', 'server_error']
                        : ['تعذّر إكمال الطلب', 'request_failed'],
                };

                return $this->withRequestIdentity($request, $responses->error(
                    $message,
                    $status,
                    null,
                    ['code' => $code],
                    $exception->getHeaders()
                ));
            }

            return $this->withRequestIdentity($request, $responses->error(
                'تعذّر إكمال الطلب الآن',
                500,
                null,
                ['code' => 'server_error']
            ));
        }

        return parent::render($request, $exception);
    }

    private function withRequestIdentity($request, Response $response): Response
    {
        $requestId = trim((string) $request->attributes->get('request_id', ''));
        if ($requestId !== '') {
            $response->headers->set('X-Request-ID', $requestId);
        }

        return $response;
    }
}
