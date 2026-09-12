<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PortfolioMediaResource;
use App\Models\User;
use App\Services\PublicPortfolioService;
use App\Services\PortfolioModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Routes require both admin-only authorization and the dashboard MFA guard. */
final class PortfolioPreviewController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected,suspended'],
        ]);
        $status = $filters['status'] ?? 'pending';
        $owners = User::query()->whereNotNull('portfolio_slug')
            ->when($status === 'pending', fn ($query) => $query
                ->whereHas('portfolioItems', fn ($items) => $items->shareable()))
            ->when($status === 'suspended', fn ($query) => $query->whereNotNull('portfolio_sharing_suspended_at'),
                fn ($query) => $query->whereNull('portfolio_sharing_suspended_at')
                    ->where('portfolio_sharing_status', $status))
            ->withCount(['portfolioItems as selected_projects_count' => fn ($items) => $items->shareable()])
            ->orderBy('updated_at')->orderBy('id')->paginate(30)->withQueryString();

        return response()->view('portfolio.moderation', compact('owners', 'status'))->withHeaders($this->headers());
    }

    public function show(User $user, PublicPortfolioService $portfolios): Response
    {
        return response()->view('portfolio.public', [
            'portfolio' => $portfolios->adminPreview($user),
            'isAdminPreview' => true,
            'reviewUser' => $user,
        ])->withHeaders($this->headers());
    }

    public function media(User $user, string $mediaId, PublicPortfolioService $portfolios, Request $request): RedirectResponse
    {
        $media = $portfolios->adminPreviewMedia($user, $mediaId,
            is_string($request->query('revision')) ? $request->query('revision') : '',
            is_string($request->query('snapshot')) ? $request->query('snapshot') : ''
        );
        abort_unless($media, 404);
        $payload = (new PortfolioMediaResource($media))->resolve();
        abort_unless(($payload['status'] ?? null) === 'ready', 404);
        $url = ($payload['file_type'] ?? null) === 'video'
            ? ($payload['video_url'] ?? null) : ($payload['image_url'] ?? null);
        abort_unless(is_string($url) && str_starts_with($url, 'https://'), 404);

        return redirect()->away($url)->withHeaders($this->headers());
    }

    public function decide(User $user, Request $request, PortfolioModerationService $moderation): RedirectResponse
    {
        $input = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'snapshot_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'decision' => ['required', 'in:approved,rejected'],
            'reason' => ['nullable', 'required_if:decision,rejected', 'string', 'max:2000'],
        ]);
        $moderation->decide($user, $request->user(), (int) $input['revision'],
            $input['snapshot_hash'], $input['decision'], $input['reason'] ?? null);

        return redirect()->route('admin.portfolio-preview.show', $user)
            ->with('success', 'تم حفظ قرار المراجعة لهذه النسخة');
    }

    private function headers(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
