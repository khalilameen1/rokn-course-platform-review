<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AdminAuthoringCreateIntentService
{
    private const TABLE = 'admin_authoring_create_intents';

    /**
     * Read a create receipt without repeating the original multipart request.
     *
     * The caller supplies the exact route, parent scope and resource type it
     * owns. This keeps the lookup bound to the same actor and course while a
     * browser still holds file inputs that cannot survive a page reload.
     *
     * @param array<string, string|int> $parents
     * @return array{state: string, resource_id?: string, payload?: array<string, mixed>}
     */
    public function resourceReceipt(
        Request $request,
        string $intentId,
        string $routeName,
        array $parents,
        string $resourceType
    ): array {
        if (!$request->user() || !Str::isUuid($intentId) || !Schema::hasTable(self::TABLE)) {
            return ['state' => 'absent'];
        }

        $row = DB::table(self::TABLE)->where([
            'actor_id' => $request->user()->id,
            'route_name' => $routeName,
            'parent_scope' => $this->parentScope($parents),
            'intent_id' => $intentId,
        ])->first();
        if (!$row) return ['state' => 'absent'];

        $state = (string) $row->status;
        if ($state !== 'completed') {
            return ['state' => in_array($state, ['processing', 'failed'], true) ? $state : 'absent'];
        }
        if ((string) ($row->resource_type ?? '') !== $resourceType || empty($row->resource_id)) {
            return ['state' => 'superseded'];
        }

        $payload = json_decode((string) ($row->response_body ?? ''), true);
        if (!is_array($payload)) return ['state' => 'superseded'];

        return [
            'state' => 'completed',
            'resource_id' => (string) $row->resource_id,
            'payload' => $payload,
        ];
    }

    public function claim(Request $request): array|Response|null
    {
        $identity = $this->identity($request);
        if (!$identity || !Schema::hasTable(self::TABLE)) return null;

        $fingerprint = $this->fingerprint($request);
        try {
            $inserted = DB::table(self::TABLE)->insertOrIgnore($identity + [
                'request_fingerprint' => $fingerprint,
                'status' => 'processing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted) return $identity + ['request_fingerprint' => $fingerprint];

            $existing = DB::table(self::TABLE)->where($identity)->first();
            if (!$existing) return null;

            $samePayload = hash_equals((string) $existing->request_fingerprint, $fingerprint);
            if ((string) $existing->status === 'completed') {
                if (!$samePayload) return $this->changedPayloadResponse($request);
                return $this->replay($request, $existing);
            }

            // A rejected validation or controller failure is a finished
            // attempt, not a lock. The browser deliberately keeps the same
            // intent while the editor corrects its fields.
            if ((string) $existing->status === 'failed') {
                if (!$samePayload && !empty($existing->resource_id)) {
                    return $this->changedPayloadResponse($request);
                }
                $reclaimed = DB::table(self::TABLE)
                    ->where($identity)
                    ->where('status', 'failed')
                    ->update($this->processingValues($fingerprint, $existing));
                if ($reclaimed === 1) return $identity + ['request_fingerprint' => $fingerprint];
            }

            if (!$samePayload) return $this->changedPayloadResponse($request);

            // Integrated create controllers commit the domain rows and the
            // completed receipt in one transaction. A stale processing row
            // therefore means that transaction never committed and is safe to
            // lease again.
            $staleBefore = !empty($existing->resource_id) || $request->allFiles() === []
                ? now()->subMinutes(5)
                : now()->subMinutes(65);
            if (
                (string) $existing->status === 'processing'
                && strtotime((string) $existing->updated_at) < $staleBefore->getTimestamp()
            ) {
                $reclaimed = DB::table(self::TABLE)
                    ->where($identity)
                    ->where('status', 'processing')
                    ->where('updated_at', '<', $staleBefore)
                    ->update(['updated_at' => now()]);
                if ($reclaimed === 1) return $identity + ['request_fingerprint' => $fingerprint];
            }

            return $this->rejectedResponse(
                $request,
                "الحفظ ما زال قيد التنفيذ\nانتظر قليلًا ثم أعد المحاولة",
                'authoring_in_progress',
                409
            );
        } catch (\Throwable $exception) {
            report($exception);
            // Once the ledger exists, executing without a claim is less safe
            // than asking the editor to retry: the same click could otherwise
            // allocate two resources while the database is degraded.
            return $this->rejectedResponse(
                $request,
                "تعذر بدء الحفظ الآن\nحاول مرة أخرى",
                'authoring_unavailable',
                503
            );
        }
    }

    public function completeRedirect(
        Request $request,
        string $location,
        int $status = 302,
        ?string $resourceType = null,
        string|int|null $resourceId = null
    ): void {
        $this->complete($request, 'redirect', $status, $location, null, null, $resourceType, $resourceId);
    }

    /**
     * Publish the newly allocated domain identity before a deterministic media
     * finalization step. A failed retry may then resume the same resource, but
     * can never reuse the intent for a different payload.
     */
    public function checkpointResource(
        Request $request,
        string $resourceType,
        string|int $resourceId
    ): void {
        $identity = $this->identity($request);
        if (!$identity || !Schema::hasTable(self::TABLE) || !$this->supportsReplayColumns()) return;
        DB::table(self::TABLE)
            ->where($identity)
            ->whereIn('status', ['processing', 'failed'])
            ->update([
                'status' => 'processing',
                'resource_type' => $resourceType,
                'resource_id' => (string) $resourceId,
                'failed_at' => null,
                'failure_code' => null,
                'updated_at' => now(),
            ]);
    }

    /** @param array<string, mixed> $payload */
    public function completeJson(
        Request $request,
        array $payload,
        int $status = 200,
        ?string $resourceType = null,
        string|int|null $resourceId = null
    ): void {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->complete(
            $request,
            'json',
            $status,
            null,
            $body,
            'application/json',
            $resourceType,
            $resourceId
        );
    }

    public function settleFromResponse(Request $request, Response $response, ?array $intent): void
    {
        if (!$intent || !Schema::hasTable(self::TABLE)) return;

        $success = $response->getStatusCode() >= 200
            && $response->getStatusCode() < 400
            && !$request->session()->has('errors')
            && !$request->session()->has('error');
        if (!$success) {
            $this->markFailed($intent, 'response_rejected');
            return;
        }

        try {
            $row = DB::table(self::TABLE)->where($intent)->first();
            if (!$row || (string) $row->status === 'completed') return;

            $location = $response->headers->get('Location');
            $contentType = $response->headers->get('Content-Type');
            $body = $response->getContent();
            $kind = $location ? 'redirect' : (str_contains(strtolower((string) $contentType), 'json') ? 'json' : 'response');
            $this->complete(
                $request,
                $kind,
                $response->getStatusCode(),
                $location,
                !$location && is_string($body) && ($kind === 'json' || strlen($body) <= 262144)
                    ? $body
                    : null,
                $contentType,
                null,
                null
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function fail(Request $request, ?array $intent, string $reason = 'controller_exception'): void
    {
        if (!$intent) return;
        $this->markFailed($intent, $reason);
    }

    private function complete(
        Request $request,
        string $kind,
        int $status,
        ?string $location,
        ?string $body,
        ?string $contentType,
        ?string $resourceType,
        string|int|null $resourceId
    ): void {
        $identity = $this->identity($request);
        if (!$identity || !Schema::hasTable(self::TABLE)) return;

        $values = [
            'status' => 'completed',
            'response_location' => $location,
            'response_status' => $status,
            'updated_at' => now(),
        ];
        if ($this->supportsReplayColumns()) {
            $values += [
                'response_kind' => $kind,
                'response_body' => $body,
                'response_content_type' => $contentType,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId === null ? null : (string) $resourceId,
                'completed_at' => now(),
                'failed_at' => null,
                'failure_code' => null,
            ];
        }
        DB::table(self::TABLE)
            ->where($identity)
            ->whereIn('status', ['processing', 'failed'])
            ->update($values);
    }

    private function markFailed(array $intent, string $reason): void
    {
        try {
            if (!Schema::hasTable(self::TABLE)) return;
            $query = DB::table(self::TABLE)->where($intent)->where('status', 'processing');
            if (!$this->supportsReplayColumns()) {
                $query->delete();
                return;
            }
            $query->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_code' => Str::limit($reason, 80, ''),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function replay(Request $request, object $receipt): Response
    {
        $status = (int) ($receipt->response_status ?: 200);
        $kind = (string) ($receipt->response_kind ?? '');
        $location = (string) ($receipt->response_location ?? '');
        $body = $receipt->response_body ?? null;

        if ($kind === 'redirect' && $location !== '') {
            return redirect()
                ->to($location, $status >= 300 && $status < 400 ? $status : 302)
                ->with('success', 'تم الحفظ بالفعل');
        }
        if (is_string($body)) {
            $response = response($body, $status);
            if (!empty($receipt->response_content_type)) {
                $response->headers->set('Content-Type', (string) $receipt->response_content_type);
            }
            return $response;
        }
        if ($location !== '') {
            return redirect()
                ->to($location, $status >= 300 && $status < 400 ? $status : 302)
                ->with('success', 'تم الحفظ بالفعل');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'code' => 'authoring_receipt_incomplete',
                'message' => "تعذر استعادة نتيجة الحفظ\nحدّث الاستوديو ثم حاول مرة أخرى",
            ], 409);
        }

        // Compatibility for a receipt written by the previous deployment,
        // which marked JSON success without storing a body or Location.
        $routeName = (string) ($request->route()?->getName() ?: '');
        $indexRoute = str_ends_with($routeName, '.store')
            ? substr($routeName, 0, -strlen('.store')).'.index'
            : '';
        if ($indexRoute !== '' && Route::has($indexRoute)) {
            return redirect()->route($indexRoute, $request->route()?->parameters() ?? [])
                ->with('success', 'تم الحفظ بالفعل');
        }
        return redirect()->back()->with('success', 'تم الحفظ بالفعل');
    }

    /** @return array<string, mixed>|null */
    private function identity(Request $request): ?array
    {
        if (strtoupper($request->method()) !== 'POST' || !$request->user()) return null;
        $intent = trim((string) $request->input('authoring_request_id'));
        if (!Str::isUuid($intent)) return null;
        $routeName = (string) ($request->route()?->getName() ?: $request->path());
        if (!str_ends_with($routeName, '.store')) return null;

        $parents = $request->route()?->parameters() ?? [];

        return [
            'actor_id' => $request->user()->id,
            'route_name' => $routeName,
            'parent_scope' => $this->parentScope($parents),
            'intent_id' => $intent,
        ];
    }

    /** @param array<string, mixed> $parents */
    private function parentScope(array $parents): string
    {
        $normalized = collect($parents)->map(
            fn ($value) => is_object($value) && method_exists($value, 'getKey')
                ? (string) $value->getKey()
                : (string) $value
        )->all();
        ksort($normalized);

        return hash('sha256', json_encode($normalized));
    }

    private function fingerprint(Request $request): string
    {
        $payload = $this->canonicalize(
            $request->except(['_token', 'authoring_draft_receipt', 'authoring_request_id'])
        );
        $files = [];
        foreach ($request->allFiles() as $name => $file) {
            $files[$name] = $this->fileFingerprint($file);
        }
        ksort($files);

        return hash('sha256', json_encode([$payload, $files]));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function fileFingerprint(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return [
                'name' => $value->getClientOriginalName(),
                'size' => $value->getSize(),
                'sha256' => hash_file('sha256', $value->getRealPath()),
            ];
        }
        if (!is_array($value)) return null;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->fileFingerprint($item);
        return $value;
    }

    /** @return array<string, mixed> */
    private function processingValues(string $fingerprint, object $existing): array
    {
        return [
            'request_fingerprint' => $fingerprint,
            'status' => 'processing',
            'response_kind' => null,
            'response_location' => null,
            'response_status' => null,
            'response_body' => null,
            'response_content_type' => null,
            'resource_type' => $existing->resource_type ?? null,
            'resource_id' => $existing->resource_id ?? null,
            'completed_at' => null,
            'failed_at' => null,
            'failure_code' => null,
            'updated_at' => now(),
        ];
    }

    private function changedPayloadResponse(Request $request): Response
    {
        return $this->rejectedResponse(
            $request,
            "تغيّرت بيانات عملية الحفظ\nأعد المحاولة من النموذج الحالي",
            'authoring_payload_changed',
            409
        );
    }

    private function rejectedResponse(
        Request $request,
        string $message,
        string $code,
        int $status
    ): Response {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'code' => $code,
                'message' => $message,
            ], $status);
        }

        return redirect()->back()->withInput()->with('error', $message);
    }

    private function supportsReplayColumns(): bool
    {
        return Schema::hasColumn(self::TABLE, 'response_kind')
            && Schema::hasColumn(self::TABLE, 'failed_at');
    }
}
