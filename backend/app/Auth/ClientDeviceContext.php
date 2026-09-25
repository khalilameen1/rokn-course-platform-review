<?php

declare(strict_types=1);

namespace App\Auth;

/** Validated device facts; provider credentials and HTTP objects never belong here. */
final readonly class ClientDeviceContext
{
    public function __construct(
        public ?string $deviceId = null,
        public ?string $deviceToken = null,
        public ?string $deviceType = null,
        public ?string $deviceOs = null,
        public ?string $platform = null,
        public ?string $deviceClass = null,
        public ?string $appVersion = null,
        public ?string $appBuild = null
    ) {}

    /** @return array{device_id:?string,platform:?string,device_class:?string,app_version:?string,app_build:?string} */
    public function sessionMetadata(): array
    {
        return [
            'device_id' => $this->deviceId,
            'platform' => $this->platform,
            'device_class' => $this->deviceClass,
            'app_version' => $this->appVersion,
            'app_build' => $this->appBuild,
        ];
    }
}
