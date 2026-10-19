<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OperationalRecoveryRequest;
use App\Http\Requests\Admin\ProductFeatureUpdateRequest;
use App\Models\OutboxEvent;
use App\Services\AdminOperationalRecoveryService;
use App\Services\AdminProductFeatureAuthoringService;
use App\Services\AdminProductOperationsReadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductOperationsController extends Controller
{
    public function index(AdminProductOperationsReadService $operations): View
    {
        return view('admin.product_operations', $operations->report());
    }

    public function retryOutbox(
        OperationalRecoveryRequest $request,
        OutboxEvent $outboxEvent,
        AdminOperationalRecoveryService $recovery
    ): RedirectResponse {
        $validated = $request->validated();
        $recovery->retryOutbox(
            (int) $outboxEvent->id,
            $request->user()?->getAuthIdentifier(),
            $validated['reason']
        );

        return back()->with('success', 'أُعيد الحدث إلى الطابور بنفس هويته دون إنشاء حدث مكرر');
    }

    public function skipOutbox(
        OperationalRecoveryRequest $request,
        OutboxEvent $outboxEvent,
        AdminOperationalRecoveryService $recovery
    ): RedirectResponse {
        $validated = $request->validated();
        $recovery->skipOutbox(
            (int) $outboxEvent->id,
            $request->user()?->getAuthIdentifier(),
            $validated['reason']
        );

        return back()->with('success', 'تم تجاوز الحدث الفاشل بقرار موثق وسيستكمل الطابور ما بعده');
    }

    public function acknowledgeFailedJob(
        OperationalRecoveryRequest $request,
        int $failedJob,
        AdminOperationalRecoveryService $recovery
    ): RedirectResponse {
        $validated = $request->validated();
        $recovery->acknowledgeFailedJob(
            $failedJob,
            $request->user()?->getAuthIdentifier(),
            $validated['reason']
        );

        return back()->with('success', 'أُغلقت المهمة الفاشلة بعد مراجعتها دون إعادة تنفيذها');
    }

    public function updateFeature(
        ProductFeatureUpdateRequest $request,
        string $feature,
        AdminProductFeatureAuthoringService $features
    ): RedirectResponse {
        abort_unless(array_key_exists($feature, config('product_features.definitions', [])), 404);
        $validated = $request->validated();
        $administrator = $request->user();
        $owner = $administrator?->email
            ?: 'admin:'.(string) ($administrator?->getAuthIdentifier() ?? 'unknown');
        try {
            $features->update($feature, $validated, $owner);
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['expires_at'])) {
                return back()->withInput()->with('error', $exception->errors()['expires_at'][0]);
            }
            throw $exception;
        }

        return back()->with('success', 'تم تحديث بوابة الميزة مع حفظ المسؤول والسبب.');
    }
}
