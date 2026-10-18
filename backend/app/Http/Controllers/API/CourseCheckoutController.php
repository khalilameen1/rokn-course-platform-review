<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\CourseCheckoutRejected;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\CourseCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** HTTP adapter for the isolated, tested checkout service. GET never fulfills. */
final class CourseCheckoutController extends Controller
{
    public function __construct(
        private readonly CourseCheckoutService $checkouts,
        private readonly ApiResponseService $responses
    ) {}

    public function create(Request $request): JsonResponse
    {
        $input = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'access_plan_code' => ['required', Rule::in(['basic', 'guided', 'mentor'])],
            'mode' => ['sometimes', Rule::in(['purchase', 'upgrade'])],
            'required_feature' => ['sometimes', Rule::in(['chat', 'project_discussion'])],
            'channel' => ['required', Rule::in(['google', 'apple', 'direct'])],
            'funding_mode' => ['sometimes', Rule::in(['package', 'exact_shortfall'])],
            'coupon_code' => ['nullable', 'string', 'min:3', 'max:50'],
            'package_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->respond(fn (User $user) => $this->checkouts->create($user, $input));
    }

    public function latest(Request $request): JsonResponse
    {
        $input = $request->validate(['course_id' => ['required', 'integer', 'min:1']]);

        return $this->respond(fn (User $user) => $this->checkouts->latest($user, (int) $input['course_id']));
    }

    public function show(string $checkout): JsonResponse
    {
        return $this->respond(fn (User $user) => $this->checkouts->show($user, $checkout));
    }

    public function resume(string $checkout): JsonResponse
    {
        return $this->respond(fn (User $user) => $this->checkouts->resume($user, $checkout));
    }

    public function authorizeCheckout(string $checkout): JsonResponse
    {
        return $this->respond(fn (User $user) => $this->checkouts->authorize($user, $checkout));
    }

    public function cancel(string $checkout): JsonResponse
    {
        return $this->respond(fn (User $user) => $this->checkouts->cancel($user, $checkout));
    }

    /** @param callable(User): ?array<string, mixed> $operation */
    private function respond(callable $operation): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        try {
            return $this->responses->success($operation($user), 'تم تحديث الاشتراك');
        } catch (\DomainException $exception) {
            $code = CourseCheckoutRejected::codeFor($exception);
            if ($code === CourseCheckoutRejected::CHECKOUT_ALREADY_PENDING) {
                return $this->responses->error(
                    "عندك محاولة دفع لم تُحسم\nارجع إليها أو ألغها قبل بدء محاولة جديدة",
                    409,
                    ['active_checkout' => $this->checkouts->awaitingSettlementSummary($user)],
                    ['code' => $code]
                );
            }

            return $this->responses->error('راجع تفاصيل الاشتراك قبل المتابعة', 409, additional: ['code' => $code]);
        }
    }
}
