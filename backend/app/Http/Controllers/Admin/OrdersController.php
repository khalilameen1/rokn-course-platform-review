<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderIndexRequest;
use App\Models\Order;
use App\Models\DesignSetting;
use App\Services\AdminPaymentOperationsReadService;
use App\Services\AdminOrderSettlementService;
use App\Services\OrderLifecycleService;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * Display a listing of orders with filtering and pagination.
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(OrderIndexRequest $request, AdminPaymentOperationsReadService $payments)
    {
        return view('admin.orders.index', $payments->index($request->validated()) + [
            'designSettings' => $this->getDesignSettings(),
            'operationStateLabels' => AdminPaymentOperationsReadService::stateLabels(),
        ]);
    }

    /**
     * Display the specified order.
     *
     * @param Order $order
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function show(Order $order, AdminPaymentOperationsReadService $payments)
    {
        return view('admin.orders.show', $payments->show($order) + [
            'designSettings' => $this->getDesignSettings(),
        ]);
    }

    public function resolveFinancialReview(
        Request $request,
        Order $order,
        OrderLifecycleService $lifecycle
    ) {
        $validated = $request->validate([
            'resolution' => 'required|in:repaid,waived',
            'note' => 'required|string|min:5|max:1000',
        ]);
        $eventKey = sprintf(
            'admin-resolution:%d:%s:%s',
            $order->id,
            $validated['resolution'],
            hash('sha256', trim($validated['note']))
        );

        try {
            $lifecycle->resolveFinancialReview(
                $order,
                $validated['resolution'],
                $eventKey,
                auth()->id(),
                trim($validated['note'])
            );
        } catch (\DomainException|\InvalidArgumentException|\UnexpectedValueException $exception) {
            return redirect()->back()->with('error', $this->lifecycleError($exception));
        }

        return redirect()->back()->with(
            'success',
            $validated['resolution'] === 'repaid'
                ? 'تم توثيق السداد وإعادة الاستحقاقات المرتبطة.'
                : 'تم توثيق الإعفاء وإغلاق المراجعة المالية.'
        );
    }

    public function compensateCourse(
        Request $request,
        Order $order,
        OrderLifecycleService $lifecycle
    ) {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'note' => ['required', 'string', 'min:8', 'max:1000'],
        ]);
        $eventKey = sprintf(
            'admin-course-compensation:%d:%s',
            $order->id,
            hash('sha256', $validated['amount'] . '|' . trim($validated['note']))
        );

        try {
            $lifecycle->compensateCourseOrder(
                $order,
                (int) $validated['amount'],
                trim($validated['note']),
                $eventKey,
                auth()->id()
            );
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $this->lifecycleError($exception));
        }

        return back()->with('success', 'أضيف التعويض إلى نفس مكونات الرصيد الأصلية مع حفظ المرجع.');
    }

    public function recordSettlement(
        Request $request,
        Order $order,
        AdminOrderSettlementService $settlements
    )
    {
        $validated = $request->validate([
            'gross_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'fee_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'net_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'settled_at' => ['required', 'date'],
            'provider_reference' => ['required', 'string', 'min:3', 'max:191'],
        ]);
        try {
            $settlements->record($order, $validated, (int) auth()->id());
        } catch (\DomainException $exception) {
            return back()->withInput()->with('error', $this->lifecycleError($exception));
        }

        return back()->with('success', 'تم توثيق كشف التسوية وإظهار الصافي المؤكد في التقارير.');
    }

    private function lifecycleError(\Throwable $exception): string
    {
        return match ($exception->getMessage()) {
            'A financially reversed order cannot be approved again.'
                => 'لا يمكن اعتماد طلب عُكس ماليًا',
            'Wallet course orders can only be created by the wallet purchase flow.'
                => 'طلبات الكورسات بعملات ركن تُنشأ من مسار الشراء فقط',
            'Provider-controlled orders require verified provider evidence.',
            'Provider-controlled orders cannot be changed manually.'
                => 'حالة هذا الطلب يحددها مزود الدفع بعد التحقق',
            'A settled order cannot be rejected. Register a refund or chargeback for finance review.',
            'A settled order cannot be cancelled. Register a refund or chargeback for finance review.'
                => 'الطلب المسدد لا يُلغى من هنا\nسجّل الاسترداد أو الاعتراض للمراجعة المالية',
            'Only an already-pending order can remain pending.',
            'Only pending orders can remain pending.'
                => 'هذه العملية متاحة للطلبات المعلقة فقط',
            'Only a pending manual order can be approved by an administrator.'
                => 'لا يمكن اعتماد طلب مغلق من لوحة الإدارة',
            'Invalid financial review resolution.'
                => 'قرار المراجعة المالية غير صالح',
            'Financial resolution event key was reused for another decision.'
                => 'تغير القرار أثناء الحفظ\nحدّث الصفحة ثم أعد المحاولة',
            'Only a package under financial review can be resolved.'
                => 'يمكن إغلاق المراجعة لباقات الشحن قيد المراجعة فقط',
            'Invalid course compensation.'
                => 'بيانات التعويض غير صالحة',
            'Only a settled wallet course order can be compensated.'
                => 'التعويض متاح لكورس مسدد بعملات ركن فقط',
            'This legacy order has no verifiable wallet debit.'
                => 'لا يوجد خصم موثق يمكن تعويضه لهذا الطلب',
            'Compensation exceeds the remaining order amount.'
                => 'قيمة التعويض أكبر من المبلغ المتبقي',
            'An order must reference exactly one course or coin package.',
            'An order must belong to a learner.',
            'Coin package order is incomplete and cannot be approved.',
            'Course order is incomplete and cannot be approved.'
                => 'بيانات الطلب غير مكتملة ولا يمكن اعتماده',
            'Only an approved paid package can be settled.'
                => 'التسوية متاحة فقط لطلب شحن مدفوع ومعتمد',
            'Test purchases cannot be settled as live revenue.'
                => 'عمليات الاختبار لا تتحول إلى إيراد حقيقي',
            'Settlement was already recorded.'
                => 'تم توثيق صافي العملية بالفعل',
            'Settlement time cannot be in the future.'
                => 'وقت التسوية لا يمكن أن يكون في المستقبل',
            'Settlement net does not equal gross minus fees.'
                => 'يجب أن يساوي الصافي الإجمالي ناقص رسوم المزود',
            'Settlement gross does not match the captured gross.'
                => 'إجمالي كشف التسوية لا يطابق إجمالي العملية المسجل',
            'Settlement currency does not match the captured currency.'
                => 'عملة كشف التسوية لا تطابق عملة العملية',
            default => $this->unexpectedLifecycleError($exception),
        };
    }

    private function unexpectedLifecycleError(\Throwable $exception): string
    {
        report($exception);

        return 'تعذّر تنفيذ التغيير\nحدّث الصفحة ثم أعد المحاولة';
    }
}
