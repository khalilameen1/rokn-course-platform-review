<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\PortfolioOperationException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PortfolioMediaResource;
use App\Models\User;
use App\Services\PortfolioMediaAuthoringService;
use App\Services\PortfolioMediaMutationService;
use App\Support\UnicodeText;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PortfolioMediaController extends Controller
{
    public function __construct(
        private PortfolioMediaAuthoringService $mediaAuthoring,
        private PortfolioMediaMutationService $mediaMutations
    ) {
    }

    public function append(Request $request, $id): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        $this->mediaAuthoring->ownedItem($user, (int) $id);

        if ($request->has('caption') && is_string($request->input('caption'))) {
            $request->merge([
                'caption' => UnicodeText::clean($request->input('caption'), false),
            ]);
        }
        if (!$request->filled('client_request_id')) {
            $candidate = trim((string) $request->header('Idempotency-Key'));
            $request->merge([
                'client_request_id' => $candidate !== '' ? $candidate : (string) Str::uuid(),
            ]);
        }
        $this->assertIdempotencyHeaderMatches($request, 'client_request_id');
        $request->validate([
            'client_request_id' => 'required|uuid',
            'file' => [
                'required',
                'file',
                'min:1',
                'max:51200',
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
            'file_type' => ['required', Rule::in(['image'])],
            'caption' => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->mediaAuthoring->appendImage(
                $user,
                (int) $id,
                $request->file('file'),
                $request->string('client_request_id')->toString(),
                $request->filled('caption') ? (string) $request->input('caption') : null
            );
        } catch (PortfolioOperationException $exception) {
            return $this->portfolioOperationError($exception);
        } catch (LockTimeoutException) {
            return $this->error("جارٍ رفع هذا الملف\nحاول بعد قليل", 409);
        }

        return $this->mediaResponse($result['media'], $result['replayed']);
    }

    public function issueVideoUpload(Request $request, $id): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        $this->mediaAuthoring->ownedItem($user, (int) $id);

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'size' => ['required', 'integer', 'min:1', 'max:' . PortfolioMediaAuthoringService::VIDEO_MAX_BYTES],
            'mime' => ['required', Rule::in(PortfolioMediaAuthoringService::VIDEO_MIMES)],
            'original_name' => ['required', 'string', 'max:255'],
            'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        $this->assertIdempotencyHeaderMatches($request, 'idempotency_key');
        try {
            $data = $this->mediaAuthoring->issueVideo($user, (int) $id, $validated);

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تجهيز رفع الفيديو',
                'data' => $data,
            ]);
        } catch (PortfolioOperationException $exception) {
            return $this->portfolioOperationError($exception);
        } catch (LockTimeoutException) {
            return $this->error("جارٍ تجهيز رفع الفيديو\nحاول بعد قليل", 409);
        }
    }

    public function renewVideoUpload(Request $request, $id): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        $this->mediaAuthoring->ownedItem($user, (int) $id);

        $validated = $request->validate(['claim' => ['required', 'string', 'max:4096']]);
        try {
            $data = $this->mediaAuthoring->renewVideo($user, (int) $id, (string) $validated['claim']);

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تجديد رابط الرفع',
                'data' => $data,
            ]);
        } catch (PortfolioOperationException $exception) {
            return $this->portfolioOperationError($exception);
        }
    }

    public function claimVideoUpload(Request $request, $id): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        $this->mediaAuthoring->ownedItem($user, (int) $id);

        if ($request->has('caption') && is_string($request->input('caption'))) {
            $request->merge(['caption' => UnicodeText::clean($request->input('caption'), false)]);
        }
        $validated = $request->validate([
            'claim' => ['required', 'string', 'max:4096'],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);
        try {
            $media = $this->mediaAuthoring->claimVideo(
                $user,
                (int) $id,
                (string) $validated['claim'],
                $validated['caption'] ?? null
            );
        } catch (PortfolioOperationException $exception) {
            return $this->portfolioOperationError($exception);
        }

        return $this->mediaResponse($media, $media->wasRecentlyCreated === false);
    }

    public function destroy($id, $mediaId): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        $deleted = $this->mediaMutations->deleteMedia($user, (int) $id, (int) $mediaId);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم حذف الملف',
            'data' => ['already_deleted' => !$deleted],
        ]);
    }

    private function mediaResponse($media, bool $replayed): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تمت إضافة الملف',
            'data' => new PortfolioMediaResource($media),
            'replayed' => $replayed,
        ]);
    }

    private function error(string $message, int $httpStatus): JsonResponse
    {
        return response()->json([
            'status' => $httpStatus,
            'success' => false,
            'message' => $message,
            'data' => null,
        ], $httpStatus);
    }

    private function assertIdempotencyHeaderMatches(Request $request, string $field): void
    {
        if (!$request->hasHeader('Idempotency-Key') || !$request->has($field)) {
            return;
        }
        $header = strtolower(trim((string) $request->header('Idempotency-Key')));
        $body = strtolower(trim((string) $request->input($field)));
        if ($header === '' || $body === '' || !hash_equals($header, $body)) {
            throw ValidationException::withMessages([
                $field => ['تغيّر معرّف الرفع أثناء التنفيذ'],
            ]);
        }
    }

    private function portfolioOperationError(PortfolioOperationException $exception): JsonResponse
    {
        [$status, $message] = match ($exception->reason) {
            PortfolioOperationException::UPLOAD_FAILED => [503, "تعذّر رفع الملف الآن\nحاول مرة أخرى"],
            PortfolioOperationException::UPLOAD_EXPIRED => [410, "انتهت صلاحية الرفع\nابدأ رفع الفيديو مرة أخرى"],
            PortfolioOperationException::IDENTITY_CONFLICT => [409, 'تعذر استكمال رفع هذا الملف'],
            default => [409, 'هذا المشروع غير متاح الآن'],
        };

        return $this->error($message, $status);
    }
}
