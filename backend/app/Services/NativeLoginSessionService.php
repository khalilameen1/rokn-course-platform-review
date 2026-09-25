<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Device policy and native bearer issuance share one learner lock. */
final class NativeLoginSessionService
{
    public function __construct(private readonly DeviceLoginService $devices)
    {
    }

    /**
     * Receive the account returned by SocialAccountBindingService. The caller
     * owns the transaction and, for browser login, the OAuth completion claim.
     * Provider/subject come from the verified binding, never raw request input.
     * HTTP request objects and provider credentials never enter this owner.
     *
     * @param array{device_id?:?string,platform?:?string,device_class?:?string,app_version?:?string,app_build?:?string} $metadata
     * @return array{access:array,api_token:?string}
     */
    public function issueWithinTransaction(
        int $userId,
        string $provider,
        string $providerUserId,
        array $metadata
    ): array {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Native session issuance must share the login-completion transaction.');
        }

        $user = User::withTrashed()->whereKey($userId)->lockForUpdate()->first();
        if (!$user || $user->trashed() || !(bool) $user->active) {
            return [
                'access' => [
                    'allowed' => false,
                    'code' => 'account_disabled',
                    'message' => "حسابك غير مفعّل\nتواصل مع الدعم",
                ],
                'api_token' => null,
            ];
        }

        $access = $this->devices->checkDeviceAccess($user, $metadata['device_id'] ?? null);
        if (!$access['allowed']) {
            return ['access' => $access, 'api_token' => null];
        }

        $this->devices->applyDeviceAction(
            $user,
            (string) $access['action'],
            (string) $access['device_id']
        );
        $token = $user->generateApiToken($provider, $providerUserId, $metadata);
        $this->devices->enforceActiveSessionLimit($user, $token);

        return ['access' => $access, 'api_token' => $token];
    }
}
