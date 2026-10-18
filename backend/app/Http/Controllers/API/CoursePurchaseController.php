<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\CourseCheckoutRejected;
use App\Exceptions\FinancialProvenanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\CourseAuthorizationRequest;
use App\Http\Requests\API\CoursePurchaseQuoteRequest;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\CoursePurchaseService;
use App\Support\CoursePurchaseOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/** HTTP adapter for the direct wallet purchase kept for the shipped course-details screen. */
final class CoursePurchaseController extends Controller
{
    public function __construct(
        private readonly CoursePurchaseService $purchases,
        private readonly ApiResponseService $responses
    ) {}

    public function quote(CoursePurchaseQuoteRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        try {
            $quote = $this->purchases->quote(
                $user,
                (int) $request->input('course_id'),
                $this->planCode($request),
                $request->input('coupon_code'),
                $request->filled('expected_course_revision')
                    ? $request->integer('expected_course_revision')
                    : null
            );
        } catch (ValidationException $exception) {
            return $this->planUnavailable($exception);
        } catch (\DomainException $exception) {
            $code = CourseCheckoutRejected::codeFor($exception);

            return $this->responses->error(
                match ($code) {
                    CourseCheckoutRejected::COURSE_TERMS_CHANGED => "تغيّرت تفاصيل الفئة\nراجعها قبل الشراء",
                    CourseCheckoutRejected::COURSE_ACCESS_CHANGED => "الكورس مضاف إلى حسابك بالفعل\nحدّث الصفحة",
                    CourseCheckoutRejected::COUPON_ALREADY_USED => 'استخدمت هذا الكود من قبل',
                    CourseCheckoutRejected::COUPON_NOT_APPLICABLE => 'لا ينطبق الخصم على هذه الفئة',
                    CourseCheckoutRejected::COUPON_QUOTA_REACHED => 'اكتمل عدد مرات استخدام هذا الكود',
                    CourseCheckoutRejected::COUPON_INVALID => 'الكود غير صحيح أو انتهت صلاحيته',
                    default => 'هذا الكورس غير متاح للشراء الآن',
                },
                $this->refusalStatus($code),
                additional: ['code' => $code]
            );
        }

        return $this->responses->success($quote, 'تم حساب الإجمالي');
    }

    public function authorizeCourse(CourseAuthorizationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        try {
            $outcome = $this->purchases->authorize(
                $user,
                (int) $request->input('course_id'),
                $this->planCode($request),
                $request->filled('idempotency_key') ? (string) $request->input('idempotency_key') : null,
                $request->input('coupon_code'),
                $request->integer('expected_price'),
                $request->filled('expected_course_revision')
                    ? $request->integer('expected_course_revision')
                    : null
            );
        } catch (FinancialProvenanceException $exception) {
            report($exception);

            return $this->responses->error(
                "تعذّر إكمال الشراء الآن\nلم يتغير رصيدك",
                409,
                additional: ['code' => CourseCheckoutRejected::FINANCIAL_PROVENANCE_UNAVAILABLE]
            );
        } catch (ValidationException $exception) {
            return $this->planUnavailable($exception);
        } catch (\DomainException $exception) {
            $code = CourseCheckoutRejected::codeFor($exception);

            return $this->responses->error(
                match ($code) {
                    CourseCheckoutRejected::CHECKOUT_IDEMPOTENCY_CONFLICT => "تغيّر طلب الشراء أثناء التنفيذ\nأعد المحاولة",
                    CourseCheckoutRejected::COUPON_ALREADY_USED => 'استخدمت هذا الكود من قبل',
                    CourseCheckoutRejected::COUPON_NOT_APPLICABLE => 'لا ينطبق الخصم على هذه الفئة',
                    CourseCheckoutRejected::COUPON_QUOTA_REACHED => 'اكتمل عدد مرات استخدام هذا الكود',
                    CourseCheckoutRejected::COUPON_INVALID => 'الكود غير صحيح أو انتهت صلاحيته',
                    CourseCheckoutRejected::COURSE_PRICE_CHANGED => "تغير السعر\nراجع الإجمالي قبل الشراء",
                    CourseCheckoutRejected::COURSE_TERMS_CHANGED => "تغيّرت تفاصيل الفئة\nراجعها قبل الشراء",
                    CourseCheckoutRejected::COURSE_PURCHASE_NOT_EFFECTIVE,
                    CourseCheckoutRejected::COURSE_ACCESS_UNDER_REVIEW => "هذه العملية قيد المراجعة\nلم يُخصم رصيد جديد",
                    default => 'هذا الكورس غير متاح للشراء الآن',
                },
                $this->refusalStatus($code),
                additional: ['code' => $code]
            );
        }

        return match ($outcome->kind) {
            CoursePurchaseOutcome::INSUFFICIENT_COINS => $this->responses->error(
                'رصيدك لا يكفي',
                400,
                $outcome->data,
                ['code' => 'insufficient_coins']
            ),
            CoursePurchaseOutcome::PLAN_UNAVAILABLE => $this->responses->error(
                $outcome->refusal ?? 'هذه الفئة لم تعد متاحة',
                422,
                additional: ['code' => 'course_plan_unavailable']
            ),
            CoursePurchaseOutcome::ACCESS_CHANGED => $this->responses->error(
                "تغيّر وصولك إلى الكورس\nحدّث الصفحة قبل المتابعة",
                409,
                $outcome->data,
                ['code' => CourseCheckoutRejected::COURSE_ACCESS_CHANGED]
            ),
            default => $this->responses->success(
                $outcome->data,
                $outcome->alreadyEnrolled ? 'الكورس مضاف إلى حسابك بالفعل' : 'تمت إضافة الكورس إلى حسابك'
            ),
        };
    }

    private function planCode(CourseAuthorizationRequest $request): string
    {
        return strtolower(trim((string) $request->input('access_plan_code')));
    }

    private function planUnavailable(ValidationException $exception): JsonResponse
    {
        return $this->responses->error(
            collect($exception->errors())->flatten()->first() ?: 'راجع الفئة المختارة',
            422,
            additional: ['code' => 'course_plan_unavailable', 'errors' => $exception->errors()]
        );
    }

    /** Coupon refusals are a correctable field; every other refusal is a stale screen. */
    private function refusalStatus(string $code): int
    {
        return in_array($code, CourseCheckoutRejected::COUPON_REJECTIONS, true) ? 422 : 409;
    }
}
