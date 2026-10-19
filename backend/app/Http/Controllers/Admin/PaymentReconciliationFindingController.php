<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PaymentFindingIndexRequest;
use App\Http\Requests\Admin\PaymentFindingTransitionRequest;
use App\Models\PaymentReconciliationFinding;
use App\Models\User;
use App\Services\PaymentReconciliationReviewService;
use App\Support\PaymentFindingEditorVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PaymentReconciliationFindingController extends Controller
{
    public function __construct(private readonly PaymentReconciliationReviewService $reviews) {}

    public function index(PaymentFindingIndexRequest $request): View
    {
        $filters = $request->validated();

        $findings = PaymentReconciliationFinding::query()
            ->with(['order', 'resolver:id,name'])
            ->when(
                $filters['state'] ?? null,
                fn ($query, $state) => $query->where('state', $state)
            )
            ->when(
                $filters['kind'] ?? null,
                fn ($query, $kind) => $query->where('kind', trim($kind))
            )
            ->when(
                $filters['order_ref'] ?? null,
                fn ($query, $orderRef) => $query->where(
                    'order_ref',
                    'like',
                    '%'.addcslashes(trim($orderRef), '%_\\').'%'
                )
            )
            ->orderByRaw("CASE state WHEN 'open' THEN 0 WHEN 'ignored' THEN 1 ELSE 2 END")
            ->latest('last_seen_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();
        $editorVersions = $findings->getCollection()->mapWithKeys(
            static fn (PaymentReconciliationFinding $finding): array => [
                $finding->getKey() => PaymentFindingEditorVersion::for($finding),
            ]
        );

        $stateCounts = PaymentReconciliationFinding::query()
            ->selectRaw('state, COUNT(*) as aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state');
        $kinds = PaymentReconciliationFinding::query()
            ->whereNotNull('kind')
            ->distinct()
            ->orderBy('kind')
            ->pluck('kind');

        return view('admin.payment-reconciliation-findings.index', compact(
            'findings',
            'filters',
            'stateCounts',
            'kinds',
            'editorVersions'
        ));
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
