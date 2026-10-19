<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppVersion;
use Illuminate\Database\Eloquent\Builder;

final class AdminAppReleaseReadService
{
    /** @return Builder<AppVersion> */
    public function listQuery(): Builder
    {
        return AppVersion::query();
    }
}
