<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FeedbackReport;
use App\Services\PortfolioModerationService;
use App\Services\PublicPortfolioService;
use App\Services\SupportCaseSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PortfolioReportController extends Controller
{
    public function store(
        Request $request,
        string $slug,
        PublicPortfolioService $portfolios,
        SupportCaseSubmissionService $submissions
    ): RedirectResponse {
        abort_unless($portfolios->find($slug, 1, 1), 404);
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:2000'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        $submissions->reportPublicPortfolio($slug, $validated, $request->ip(), $request->userAgent());

        return redirect()->route('portfolio.public', $slug)->with('portfolio_report_sent', true);
    }

    public function moderate(Request $request, FeedbackReport $feedback, PortfolioModerationService $moderation): RedirectResponse
    {
        abort_unless($feedback->screen_key === 'public_portfolio_report', 404);
        $validated = $request->validate(['suspend' => ['required', 'boolean']]);
        $moderation->setSharingSuspended($feedback, (int) $request->user()->id, (bool) $validated['suspend']);

        return back()->with('success', 'تم تحديث مشاركة الأعمال');
    }
}
