<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SupportCaseCompensationRequest;
use App\Http\Requests\Admin\SupportCaseIndexRequest;
use App\Http\Requests\Admin\SupportCaseMessageRequest;
use App\Http\Requests\Admin\SupportCaseStateRequest;
use App\Models\FeedbackAttachment;
use App\Models\FeedbackReport;
use App\Services\AdminSupportCaseReadService;
use App\Services\SupportCaseAttachmentDeliveryService;
use App\Services\SupportCaseCompensationService;
use App\Services\SupportCaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FeedbackController extends Controller
{
    public function index(SupportCaseIndexRequest $request, AdminSupportCaseReadService $reads): View
    {
        return view('admin.feedback.index', [...$reads->index($request->validated()), 'filters' => $request->validated()]);
    }

    public function show(FeedbackReport $feedback, AdminSupportCaseReadService $reads): View
    {
        return view('admin.feedback.show', $reads->show($feedback));
    }

    public function export(SupportCaseIndexRequest $request, AdminSupportCaseReadService $reads): StreamedResponse
    {
        return $reads->export($request->validated());
    }

    public function update(
        SupportCaseStateRequest $request,
        FeedbackReport $feedback,
        SupportCaseService $cases
    ): RedirectResponse {
        $validated = $request->validated();
        $cases->updateState($feedback, $validated, $request->user()?->id);

        return back()->with('success', 'تم تحديث الحالة');
    }

    public function message(
        SupportCaseMessageRequest $request,
        FeedbackReport $feedback,
        SupportCaseService $cases
    ): RedirectResponse {
        $validated = $request->validated();
        $cases->appendStaffMessage(
            $feedback,
            $request->user(),
            trim($validated['message']),
            $validated['visibility'],
            $validated['client_request_id'],
            (int) $validated['version']
        );

        return back()->with('success', $validated['visibility'] === 'internal' ? 'تم حفظ الملاحظة الداخلية' : 'تم إرسال الرد');
    }

    public function compensate(
        SupportCaseCompensationRequest $request,
        FeedbackReport $feedback,
        SupportCaseCompensationService $compensation
    ): RedirectResponse {
        $validated = $request->validated();
        if (! $feedback->getAttribute('order_id')) {
            return back()->with('error', 'اربط البلاغ بطلب موثّق أولًا');
        }
        try {
            $compensation->compensate($feedback, $validated, $request->user()?->id);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return back()->withInput()->with('error', "لا يمكن إضافة هذا التعويض\nتحقق من أن المبلغ لا يتجاوز الحد المتاح");
        }

        return back()->with('success', 'أُضيفت العملات إلى محفظة الطالب وربط التعويض بالبلاغ');
    }

    public function attachment(
        FeedbackReport $feedback,
        FeedbackAttachment $attachment,
        SupportCaseAttachmentDeliveryService $delivery
    ): Response {
        abort_unless((int) $attachment->feedback_report_id === (int) $feedback->id, 404);
        $bytes = $delivery->bytes($attachment);

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="'.$feedback->public_id.'.jpg"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
