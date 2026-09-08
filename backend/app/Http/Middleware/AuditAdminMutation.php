<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use App\Models\Course;
use App\Services\AdminAuthoringCreateIntentService;
use App\Support\PrivacyFingerprint;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuditAdminMutation
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const SENSITIVE_FIELDS = [
        'password', 'password_confirmation', 'current_password',
        'token', 'api_token', 'access_token', 'secret',
        'code', 'one_time_code', 'recovery_code',
        'bunny_api_key', 'bunny_storage_password', 'bunny_security_key',
    ];

    public function __construct(
        private readonly AdminAuthoringCreateIntentService $createIntents
    ) {
    }

    public function handle(Request $request, Closure $next)
    {
        $requestId = $this->requestId($request);
        $request->headers->set('X-Request-ID', $requestId);

        $request->attributes->remove(AdminAuthoringCreateIntentService::CLAIM_ATTRIBUTE);
        $createIntent = $this->createIntents->claim($request);
        if ($createIntent instanceof \Symfony\Component\HttpFoundation\Response) {
            $createIntent->headers->set('X-Request-ID', $requestId);
            $this->consumeDraftReceipt($request, $createIntent);
            return $createIntent;
        }
        if (is_array($createIntent)) {
            $request->attributes->set(AdminAuthoringCreateIntentService::CLAIM_ATTRIBUTE, $createIntent);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            // Integrated create controllers complete the receipt in the same
            // transaction as their domain rows. This conditional failure mark
            // therefore cannot overwrite a committed success.
            $this->createIntents->fail($request, $createIntent);
            throw $exception;
        }
        $response->headers->set('X-Request-ID', $requestId);
        $this->createIntents->settleFromResponse($request, $response, $createIntent);

        if (!in_array(strtoupper($request->method()), self::MUTATING_METHODS, true)) {
            return $response;
        }

        $draftReceipt = trim((string) $request->input('authoring_draft_receipt'));
        $draftConsumed = $this->consumeDraftReceipt($request, $response);

        try {
            if (!Schema::hasTable('admin_audit_logs') || !$request->user()) {
                return $response;
            }

            $fields = collect(array_keys($request->except(self::SENSITIVE_FIELDS)))
                ->filter(fn ($field) => is_string($field) && strlen($field) <= 100)
                ->values()
                ->all();
            $parameters = collect($request->route()?->parameters() ?? [])
                ->map(function ($value) {
                    if (is_object($value) && method_exists($value, 'getKey')) {
                        return (string) $value->getKey();
                    }
                    return is_scalar($value) ? (string) $value : get_debug_type($value);
                })
                ->all();
            $courseParameter = $request->route('course');
            $courseId = is_object($courseParameter) && method_exists($courseParameter, 'getKey')
                ? (int) $courseParameter->getKey()
                : (is_numeric($courseParameter) ? (int) $courseParameter : null);
            if ($courseId) {
                $parameters['course_id'] = (string) $courseId;
                if ($request->filled('authoring_version')) {
                    $parameters['expected_authoring_version'] = (string) $request->integer('authoring_version');
                }
                $currentVersion = Course::query()->whereKey($courseId)->value('authoring_version');
                $parameters['resulting_authoring_version'] = $currentVersion === null
                    ? 'deleted'
                    : (string) $currentVersion;
            }
            if ($draftConsumed) {
                // Persist the successful browser acknowledgement in the audit
                // ledger too. A later login can still consume the local draft
                // even after the original dashboard session has expired.
                $parameters['authoring_draft_receipt'] = $draftReceipt;
            }

            AdminAuditLog::query()->create([
                'request_id' => $requestId,
                'actor_id' => $request->user()->id,
                'actor_role' => (string) $request->user()->role,
                'route_name' => $request->route()?->getName(),
                'http_method' => strtoupper($request->method()),
                'path' => Str::limit($request->path(), 500, ''),
                'route_parameters' => $parameters,
                // Field names are enough for traceability. Never duplicate
                // credentials or personal values into the audit trail.
                'request_fields' => $fields,
                'response_status' => $response->getStatusCode(),
                'ip_address' => PrivacyFingerprint::make($request->ip()),
                'user_agent' => PrivacyFingerprint::make($request->userAgent()),
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            // A logging outage is reported, but must not make a successful
            // browser mutation retry and execute twice.
            report($exception);
        }

        return $response;
    }

    private function requestId(Request $request): string
    {
        $candidate = trim((string) $request->header('X-Request-ID'));
        if ($candidate !== '' && preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $candidate) === 1) {
            return $candidate;
        }

        return (string) Str::uuid();
    }

    private function consumeDraftReceipt(
        Request $request,
        \Symfony\Component\HttpFoundation\Response $response
    ): bool {
        $draftReceipt = trim((string) $request->input('authoring_draft_receipt'));
        $consumed = Str::isUuid($draftReceipt)
            && $response->getStatusCode() >= 200
            && $response->getStatusCode() < 400
            && !$request->session()->has('errors')
            && !$request->session()->has('error');
        if (!$consumed) return false;

        $receipts = (array) $request->session()->get('admin_authoring_draft_receipts', []);
        $cutoff = now()->subDays(7)->getTimestamp();
        $receipts = array_filter(
            $receipts,
            static fn ($timestamp): bool => is_numeric($timestamp) && (int) $timestamp >= $cutoff
        );
        $receipts[$draftReceipt] = now()->getTimestamp();
        if (count($receipts) > 256) {
            asort($receipts);
            $receipts = array_slice($receipts, -256, null, true);
        }
        $request->session()->put('admin_authoring_draft_receipts', $receipts);
        try {
            if ($request->user() && Schema::hasTable('admin_authoring_draft_receipts')) {
                DB::table('admin_authoring_draft_receipts')->insertOrIgnore([
                    'receipt' => $draftReceipt,
                    'actor_id' => $request->user()->id,
                    'consumed_at' => now(),
                ]);
                DB::table('admin_authoring_draft_receipts')
                    ->where('consumed_at', '<', now()->subDays(7))
                    ->delete();
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
        return true;
    }

}
