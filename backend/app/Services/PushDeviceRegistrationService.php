<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\ClientDeviceContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One current push/account binding per installation, without changing opt-in. */
final class PushDeviceRegistrationService
{
    public function register(int $userId, ClientDeviceContext $device): void
    {
        $deviceToken = $device->deviceToken;
        $deviceType = $device->deviceType;
        $deviceOs = $this->normalizeDeviceOs($device->deviceOs);

        DB::transaction(function () use ($userId, $device, $deviceToken, $deviceType, $deviceOs): void {
            $lockedUser = User::query()->whereKey($userId)->lockForUpdate()->first();
            if (!$lockedUser || !(bool) $lockedUser->active || $lockedUser->trashed()) {
                return;
            }

            if ($deviceToken) {
                // A native token belongs to one account at a time. Reassigning
                // the unique row atomically closes reinstall/account-switch
                // delivery to its previous owner.
                $tokenAttributes = [
                    'user_id' => $lockedUser->id,
                    'device_type' => $deviceType,
                ];
                $tokenAttributes['device_os'] = $deviceOs;
                $deviceId = trim((string) $device->deviceId);
                $tokenAttributes['device_id'] = Str::isUuid($deviceId) ? $deviceId : null;
                if ($tokenAttributes['device_id']) {
                    // One installation has one current FCM/account owner.
                    // Retire an older token before binding its replacement,
                    // including an interrupted account switch or rotation.
                    \App\Models\UserDeviceToken::query()
                        ->where('device_id', $tokenAttributes['device_id'])
                        ->where('device_token', '<>', $deviceToken)
                        ->delete();
                }

                \App\Models\UserDeviceToken::updateOrCreate(
                    ['device_token' => $deviceToken],
                    $tokenAttributes
                );
            }

            if ($deviceOs && $lockedUser->device_os !== $deviceOs) {
                $lockedUser->forceFill(['device_os' => $deviceOs])->save();
            }
        }, 3);
    }

    private function normalizeDeviceOs(?string $deviceOs): ?string
    {
        $value = strtolower(trim((string) $deviceOs));

        if (str_starts_with($value, 'android')) {
            return 'android';
        }

        if (str_starts_with($value, 'ios')) {
            return 'ios';
        }

        return null;
    }
}
