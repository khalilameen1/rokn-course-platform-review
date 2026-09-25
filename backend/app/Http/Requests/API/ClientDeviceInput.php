<?php

declare(strict_types=1);

namespace App\Http\Requests\API;

use App\Auth\ClientDeviceContext;
use Illuminate\Http\Request;

/** HTTP-only device validation and header mapping shared by login entry points. */
final class ClientDeviceInput
{
    public static function rules(bool $requirePushToken = false): array
    {
        return [
            'device_os' => 'nullable|string|max:255',
            'device_token' => ($requirePushToken ? 'required' : 'nullable').'|string|max:500',
            'device_type' => 'nullable|string|max:50',
            'device_id' => ['nullable', 'uuid'],
        ];
    }

    public static function fromValidated(
        Request $request,
        array $validated,
        ?string $authenticatedDeviceId = null
    ): ClientDeviceContext {
        return new ClientDeviceContext(
            deviceId: $authenticatedDeviceId ?: ($validated['device_id'] ?? null),
            deviceToken: $validated['device_token'] ?? null,
            deviceType: $validated['device_type'] ?? null,
            deviceOs: $validated['device_os'] ?? null,
            platform: $request->header(
                'X-Rokn-Platform',
                $validated['device_os'] ?? ($validated['device_type'] ?? 'other')
            ),
            deviceClass: $request->header('X-Rokn-Device-Class'),
            appVersion: $request->header('X-Rokn-App-Version'),
            appBuild: $request->header('X-Rokn-App-Build'),
        );
    }
}
