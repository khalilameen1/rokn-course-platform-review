<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Bill;
use App\Models\PaymentMethod;
use App\Services\CourseChatAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CourseAuthorizationController extends Controller
{
    public function __construct(
        private readonly CourseChatAccessService $courseAccess
    ) {
    }

    /**
     * Get active payment methods.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPaymentMethods(): JsonResponse
    {
        $paymentMethods = PaymentMethod::active()->configured()->get()
            ->map(function ($method) {
                return [
                    'id' => $method->id,
                    'name' => $method->name,
                    'account_details' => $method->account_details,
                    'description' => $method->description,
                ];
            });

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل طرق الدفع',
            'data' => $paymentMethods
        ]);
    }

    /**
     * Check if user has access to a course.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkAccess(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id'
        ]);

        $user = auth('api')->user();
        $courseId = $request->course_id;

        // Keep this legacy endpoint on the same entitlement contract as the
        // course-details resource. Direct enrollment rows can also represent
        // course-code/institutional grants, so they must not be labelled paid.
        $resolution = $this->courseAccess->resolveEntitlement(
            (int) $user->id,
            (int) $courseId
        );
        $entitlement = $resolution['entitlement'];
        $enrollment = $entitlement['has_learning_access']
            ? $resolution['enrollment']
            : null;
        $hasAccess = $enrollment !== null;

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم التحقق من الوصول',
            'data' => [
                'has_access' => $hasAccess,
                'access_type' => $entitlement['access_type'],
                'chat_available' => $entitlement['chat_available'],
                'certificate_available' => $entitlement['certificate_available'],
                'enrollment' => $hasAccess ? [
                    'id' => $enrollment->id,
                    'enrolled_at' => $enrollment->enrolled_at,
                    'expires_at' => $enrollment->expires_at,
                    'access_granted_at' => $enrollment->access_granted_at
                ] : null
            ]
        ]);
    }

    /**
     * Get user's course purchase orders.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function myCourseOrders(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        try {
            $user = auth('api')->user();

            $orders = Order::with(['course', 'courseCode', 'coupon', 'approvedBy', 'bill'])
                ->where('user_id', $user->id)
                ->whereNotNull('course_id') // Only course orders
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل عمليات شراء الكورسات',
                'data' => [
                    'orders' => $orders->map(function ($order) {
                        $course = $order->course;
                        return [
                            'id' => $order->id,
                            'course' => $course ? [
                                'id' => $course->id,
                                'title' => $course->name_ar,
                                'title_en' => $course->name_en,
                                'image' => $course->image,
                                'price' => $course->price,
                                'retired' => (bool) $course->trashed(),
                            ] : [
                                'id' => $order->course_id,
                                'title' => 'كورس ركن',
                                'title_en' => null,
                                'image' => null,
                                'price' => $order->amount,
                                'retired' => true,
                            ],
                            'payment_method' => $order->payment_method,
                            'payment_method_display' => $this->getPaymentMethodDisplay($order->payment_method),
                            'amount' => $order->amount,
                            'discount_amount' => $order->discount_amount,
                            'final_amount' => $order->final_amount,
                            'coin_allocation' => $order->total_coins !== null ? [
                                'total_coins' => (int) $order->total_coins,
                                'paid_coins' => (int) $order->paid_coins,
                                'reward_coins' => (int) $order->reward_coins,
                                'spend_policy' => 'reward_first_then_paid',
                            ] : null,
                            'status' => $order->status,
                            'status_display' => $this->getOrderStatusDisplay($order->status),
                            'financial_status' => $order->financial_status,
                            'reversed_at' => $order->reversed_at,
                            'course_code' => $order->courseCode ? [
                                'code' => $order->courseCode->code,
                                'type' => $order->courseCode->type
                            ] : null,
                            'coupon' => $order->coupon ? [
                                'code' => $order->coupon->code,
                                'discount_type' => $order->coupon->discount_type,
                                'discount_value' => $order->coupon->discount_value
                            ] : null,
                            'payment_screenshot' => $order->payment_screenshot_url,
                            'notes' => $order->notes,
                            'approved_at' => $order->approved_at,
                            'approved_by' => $order->approvedBy ? [
                                'id' => $order->approvedBy->id,
                                'name' => $order->approvedBy->name
                            ] : null,
                            'created_at' => $order->created_at,
                            'updated_at' => $order->updated_at
                        ];
                    }),
                    'pagination' => [
                        'current_page' => $orders->currentPage(),
                        'last_page' => $orders->lastPage(),
                        'per_page' => $orders->perPage(),
                        'total' => $orders->total(),
                        'from' => $orders->firstItem(),
                        'to' => $orders->lastItem()
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّر تحميل عمليات شراء الكورسات',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Get user's billing history.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function myBills(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        try {
            $user = auth('api')->user();

            $bills = Bill::with(['order.course', 'order.courseCode', 'order.coupon'])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل سجل الدفع',
                'data' => [
                    'bills' => $bills->map(function ($bill) {
                        return [
                            'id' => $bill->id,
                            'bill_number' => $bill->bill_number,
                            'order' => $bill->order ? [
                                'id' => $bill->order->id,
                                'course' => $bill->order->course ? [
                                    'id' => $bill->order->course->id,
                                    'title' => $bill->order->course->name_ar,
                                    'title_en' => $bill->order->course->name_en,
                                    'image' => $bill->order->course->image
                                ] : null,
                                'payment_method' => $bill->order->payment_method,
                                'payment_screenshot' => $bill->order->payment_screenshot_url,
                                'course_code' => $bill->order->courseCode ? [
                                    'code' => $bill->order->courseCode->code
                                ] : null,
                                'coupon' => $bill->order->coupon ? [
                                    'code' => $bill->order->coupon->code
                                ] : null,
                                'status' => $bill->order->status,
                                'financial_status' => $bill->order->financial_status,
                                'reversed_at' => $bill->order->reversed_at,
                            ] : null,
                            'amount' => $bill->amount,
                            'tax_amount' => $bill->tax_amount,
                            'total_amount' => $bill->total_amount,
                            'payment_status' => $bill->payment_status,
                            'payment_status_display' => $this->getPaymentStatusDisplay($bill->payment_status),
                            'payment_method' => $bill->payment_method,
                            'due_date' => $bill->due_date,
                            'paid_at' => $bill->paid_at,
                            'notes' => $bill->notes,
                            'created_at' => $bill->created_at,
                            'updated_at' => $bill->updated_at
                        ];
                    }),
                    'pagination' => [
                        'current_page' => $bills->currentPage(),
                        'last_page' => $bills->lastPage(),
                        'per_page' => $bills->perPage(),
                        'total' => $bills->total(),
                        'from' => $bills->firstItem(),
                        'to' => $bills->lastItem()
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّر تحميل سجل الدفع',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Get payment method display name.
     */
    private function getPaymentMethodDisplay(mixed $paymentMethod): string
    {
        switch ($paymentMethod) {
            case 'online':
                return 'دفع إلكتروني';
            case 'course_code':
                return 'كود جهة تعليمية';
            case 'wallet':
                return 'محفظة ركن';
            default:
                return ucfirst((string) $paymentMethod);
        }
    }

    /**
     * Get order status display name.
     */
    private function getOrderStatusDisplay(mixed $status): string
    {
        switch ($status) {
            case 'pending':
                return 'قيد المراجعة';
            case 'approved':
                return 'مقبول';
            case 'rejected':
                return 'مرفوض';
            case 'cancelled':
                return 'ملغي';
            default:
                return ucfirst((string) $status);
        }
    }

    /**
     * Get payment status display name.
     */
    private function getPaymentStatusDisplay(mixed $status): string
    {
        switch ($status) {
            case 'pending':
                return 'بانتظار الدفع';
            case 'paid':
                return 'مدفوع';
            case 'failed':
                return 'تعذّر الدفع';
            case 'refunded':
                return 'تم رد المبلغ';
            default:
                return ucfirst((string) $status);
        }
    }


}
