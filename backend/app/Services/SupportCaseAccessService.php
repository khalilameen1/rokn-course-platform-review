<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeedbackReport;
use App\Models\User;

/** Guest credentials and viewer authorization, independent of the HTTP transport. */
final class SupportCaseAccessService
{
    public function createGuestCredential(string $clientRequestId): array
    {
        $secret = (string) config('app.key');
        abort_if($secret === '', 503, 'تعذّر فتح المتابعة الآن');
        $bytes = hash_hmac('sha256', 'support-case|'.$clientRequestId, $secret, true);
        $token = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        return ['token' => $token, 'hash' => hash('sha256', $token)];
    }

    public function authorizeViewer(FeedbackReport $report, ?User $user, ?string $accessToken): void
    {
        if ($user && (int) $report->user_id === (int) $user->id) {
            return;
        }
        $digest = trim((string) $report->guest_access_hash);
        $candidate = trim((string) $accessToken);
        if ($digest !== '' && $candidate !== '' && hash_equals($digest, hash('sha256', $candidate))) {
            return;
        }
        abort(404);
    }
}
