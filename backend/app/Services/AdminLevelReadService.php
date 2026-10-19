<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Level;
use Illuminate\Database\Eloquent\Builder;

final class AdminLevelReadService
{
    /** @return Builder<Level> */
    public function listQuery(): Builder
    {
        return Level::query()->with('photo');
    }
}
