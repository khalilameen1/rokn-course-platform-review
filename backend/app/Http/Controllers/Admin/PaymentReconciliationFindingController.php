<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\PaymentFindingResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PaymentFindingIndexRequest;
use App\Http\Requests\Admin\PaymentFindingTransitionRequest;
use App\Models\PaymentReconciliationFinding;
use App\Models\User;
use App\Services\PaymentReconciliationReviewService;
use Illuminate\Http\RedirectResponse;

class PaymentReconciliationFindingController extends Controller
{
    public function __construct(private readonly PaymentReconciliationReviewService $reviews) {}

    public function index(PaymentFindingIndexRequest $request): RedirectResponse
    {
        return redirect()->to(PaymentFindingResource::listUrl($request->validated()));
    }

    public function resolve(
        PaymentFindingTransitionRequest $request,
        PaymentReconciliationFinding $paymentReconciliationFinding
    ): RedirectResponse {
        return $this->transition(
            $request,
            $paymentReconciliationFinding,
            PaymentReconciliationFinding::STATE_RESOLVED,
            'تم تعليم نتيجة التسوية كمحلولة.'
        );
    }

    public function ignore(
        PaymentFindingTransitionRequest $request,
        PaymentReconciliationFinding $paymentReconciliationFinding
    ): RedirectResponse {
        return $this->transition(
            $request,
            $paymentReconciliationFinding,
            PaymentReconciliationFinding::STATE_IGNORED,
            'تم تجاهل نتيجة التسوية مع تسجيل السبب.'
        );
    }

    public function reopen(
        PaymentFindingTransitionRequest $request,
        PaymentReconciliationFinding $paymentReconciliationFinding
    ): RedirectResponse {
        return $this->transition(
            $request,
            $paymentReconciliationFinding,
            PaymentReconciliationFinding::STATE_OPEN,
            'أُعيد فتح نتيجة التسوية للمراجعة.'
        );
    }

    private function transition(
        PaymentFindingTransitionRequest $request,
        PaymentReconciliationFinding $finding,
        string $targetState,
        string $successMessage
    ): RedirectResponse {
        $validated = $request->validated();
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $this->reviews->transition(
            (int) $finding->getKey(),
            $targetState,
            $validated['note'],
            $validated['editor_version'],
            (int) $actor->getKey()
        );

        return back()->with('success', $successMessage);
    }
}
