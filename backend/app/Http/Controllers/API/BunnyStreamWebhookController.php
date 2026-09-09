<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProbeLessonMedia;
use App\Support\DurableJobDispatch;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Treat Bunny webhooks as an acceleration hint only. The queued control-plane
 * probe remains authoritative, which makes duplicate and out-of-order webhook
 * delivery harmless.
 */
final class BunnyStreamWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = trim((string) config('bunny.webhook_secret'));
        $signature = strtolower(trim((string) $request->header('X-BunnyStream-Signature')));
        if (
            $secret === ''
            || $request->header('X-BunnyStream-Signature-Version') !== 'v1'
            || strtolower((string) $request->header('X-BunnyStream-Signature-Algorithm')) !== 'hmac-sha256'
            || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1
            || !hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)
        ) {
            abort(404);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            abort(404);
        }
        $libraryId = trim((string) ($payload['VideoLibraryId'] ?? ''));
        $videoGuid = strtolower(trim((string) ($payload['VideoGuid'] ?? '')));
        // This is a notification event (0..10), not GET VideoModelStatus
        // (0..8). Never persist or classify it as a provider video status.
        $status = filter_var($payload['Status'] ?? null, FILTER_VALIDATE_INT);
        if (
            !hash_equals(trim((string) config('bunny.library_id')), $libraryId)
            || preg_match('/^[a-f0-9-]{36}$/', $videoGuid) !== 1
            || $status === false
            || $status < 0
            || $status > 10
        ) {
            abort(404);
        }

        Lesson::query()
            ->where('bunny_video_id', $videoGuid)
            ->whereHas('courseSection')
            ->pluck('id')
            ->each(fn ($lessonId) => DurableJobDispatch::now(
                new ProbeLessonMedia((int) $lessonId, $videoGuid)
            ));

        return response()->json(['accepted' => true], 202);
    }
}
