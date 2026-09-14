<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\CourseCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** HTTP adapter for the isolated, tested checkout service. GET never fulfills. */
final class CourseCheckoutController extends Controller
{
    public function __construct(private readonly CourseCheckoutService $checkouts) {}

    public function create(Request $request): JsonResponse
    {
        $input = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'access_plan_code' => ['required', Rule::in(['basic', 'guided', 'mentor'])],
            'mode' => ['sometimes', Rule::in(['purchase', 'upgrade'])],
            'channel' => ['required', Rule::in(['google', 'apple', 'direct'])],
            'coupon_code' => ['nullable', 'string', 'min:3', 'max:50'],
            'package_id' => ['nullable', 'integer', 'min:1'],
        ]);
        return $this->respond(fn () => $this->checkouts->create(auth('api')->user(), $input));
    }

    public function latest(Request $request): JsonResponse
    {
        $input = $request->validate(['course_id' => ['required', 'integer', 'min:1']]);
        return $this->respond(fn () => $this->checkouts->latest(auth('api')->user(), (int) $input['course_id']));
    }

    public function show(string $checkout): JsonResponse
    {
        return $this->respond(fn () => $this->checkouts->show(auth('api')->user(), $checkout));
    }

    public function resume(string $checkout): JsonResponse
    {
        return $this->respond(fn () => $this->checkouts->resume(auth('api')->user(), $checkout));
    }

    public function authorizeCheckout(string $checkout): JsonResponse
    {
        return $this->respond(fn () => $this->checkouts->authorize(auth('api')->user(), $checkout));
    }

    public function cancel(string $checkout): JsonResponse
    {
        return $this->respond(fn () => $this->checkouts->cancel(auth('api')->user(), $checkout));
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json(['status' => 200, 'success' => true, 'data' => $operation(), 'message' => 'تم تحديث الاشتراك']);
        } catch (\DomainException $exception) {
            if ($exception->getMessage() === 'checkout_already_pending') {
                $pending = \App\Models\CourseCheckout::query()->where('user_id', auth('api')->id())
                    ->where('status', 'pending_payment')->where('expires_at', '>', now())->latest('id')->first();
                return response()->json(['status' => 409, 'success' => false, 'code' => 'checkout_already_pending',
                    'message' => "عندك محاولة دفع لم تُحسم\nارجع إليها أو ألغها قبل بدء محاولة جديدة",
                    'data' => ['active_checkout' => $pending ? ['id' => $pending->public_id, 'course_id' => (int) $pending->course_id] : null]], 409);
            }
            return response()->json(['status' => 409, 'success' => false, 'code' => $exception->getMessage(),
                'message' => 'راجع تفاصيل الاشتراك قبل المتابعة', 'data' => null], 409);
        }
    }
}
