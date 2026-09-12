<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FeedbackReport;
use App\Models\User;
use App\Services\PublicPortfolioService;
use App\Services\SupportCaseService;
use App\Support\PrivacyFingerprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PortfolioReportController extends Controller
{
    public function store(Request $request, string $slug, PublicPortfolioService $portfolios, SupportCaseService $cases): RedirectResponse
    {
        abort_unless($portfolios->find($slug, 1, 1), 404);
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:2000'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        $owner = User::query()->where('portfolio_slug', $slug)->firstOrFail();
        // A short duplicate window handles back/refresh without suppressing later reports.
        $fingerprint = hash_hmac('sha256', implode('|', [
            $slug, trim($validated['message']), (string) $request->ip(),
        ]), (string) config('app.key'));
        if (!FeedbackReport::query()->where('request_fingerprint', $fingerprint)
            ->where('created_at', '>=', now()->subMinutes(10))->exists()) {
            DB::transaction(function () use ($request, $validated, $owner, $slug, $fingerprint, $cases): void {
                $report = FeedbackReport::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'client_request_id' => (string) Str::uuid(),
                    'request_fingerprint' => $fingerprint,
                    'requester_email' => isset($validated['email']) ? strtolower(trim($validated['email'])) : null,
                    'category' => 'course_content',
                    'status' => 'new',
                    'priority' => 'high',
                    'message' => trim($validated['message']),
                    'screen_key' => 'public_portfolio_report',
                    'platform' => 'web',
                    'locale' => 'ar',
                    'context' => ['portfolio_owner_id' => $owner->id, 'portfolio_slug' => $slug],
                    'ip_hash' => PrivacyFingerprint::make($request->ip()),
                    'user_agent' => PrivacyFingerprint::make($request->userAgent()),
                    'first_response_due_at' => $cases->firstResponseDueAt('high'),
                    'retention_until' => now()->addDays(max(30, (int) config('retention.support_cases_days', 365))),
                ]);
                $cases->event($report, null, 'created', null, 'new');
            });
        }

        return redirect()->route('portfolio.public', $slug)->with('portfolio_report_sent', true);
    }

    public function moderate(Request $request, FeedbackReport $feedback, SupportCaseService $cases): RedirectResponse
    {
        abort_unless($feedback->screen_key === 'public_portfolio_report', 404);
        $validated = $request->validate(['suspend' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $feedback, $validated, $cases): void {
            $owner = User::query()->lockForUpdate()->findOrFail($feedback->context['portfolio_owner_id'] ?? null);
            $suspend = (bool) $validated['suspend'];
            if ((bool) $owner->portfolio_sharing_suspended_at === $suspend) {
                return;
            }
            $owner->forceFill(['portfolio_sharing_suspended_at' => $suspend ? now() : null])->save();
            $cases->event($feedback, $request->user()->id, $suspend ? 'portfolio_sharing_suspended' : 'portfolio_sharing_restored');
        });

        return back()->with('success', 'تم تحديث مشاركة الأعمال');
    }
}
