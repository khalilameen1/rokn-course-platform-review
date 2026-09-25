<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OutboxEvent;
use App\Services\AdminOperationalRecoveryService;
use App\Services\AdminProductFeatureAuthoringService;
use App\Services\AdminProductOperationsReadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductOperationsController extends Controller
{
    public function index(AdminProductOperationsReadService $operations): View
    {
        return view('admin.product_operations', $operations->report());
    }

    public function retryOutbox(
        Request $request,
        OutboxEvent $outboxEvent,
        AdminOperationalRecoveryService $recovery
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:190'],
        ]);
        $recovery->retryOutbox(
            (int) $outboxEvent->id,
            $request->user()?->getAuthIdentifier(),
            $validated['reason']
        );

        return back()->with('success', 'أُعيد الحدث إلى الطابور بنفس هويته دون إنشاء حدث مكرر');
    }

    public function skipOutbox(
        Request $request,
        OutboxEvent $outboxEvent,
        AdminOperationalRecoveryService $recovery
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:190'],
        ]);
        $recovery->skipOutbox(
            (int) $outboxEvent->id,
            $request->user()?->getAuthIdentifier(),
            $validated['reason']
        );

        return back()->with('success', 'تم تجاوز الحدث الفاشل بقرار موثق وسيستكمل الطابور ما بعده');
    }

    public function acknowledgeFailedJob(
        Request $request,
        int $failedJob,
        AdminOperationalRecoveryService $recovery
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:190'],
        ]);
        $recovery->acknowledgeFailedJob(
            $failedJob,
            $request->user()?->getAuthIdentifier(),
            $validated['reason']
        );

        return back()->with('success', 'أُغلقت المهمة الفاشلة بعد مراجعتها دون إعادة تنفيذها');
    }

    public function updateFeature(
        Request $request,
        string $feature,
        AdminProductFeatureAuthoringService $features
    ): RedirectResponse {
        abort_unless(array_key_exists($feature, config('product_features.definitions', [])), 404);
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'rollout_percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'reason' => ['required', 'string', 'min:8', 'max:255'],
            'expires_at' => ['nullable', 'date'],
            'editor_version' => ['required', 'string', 'size:64'],
        ]);
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
