<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Builder;

final readonly class AdminCouponReadService
{
    public function __construct(private AdminContentInventoryReadService $inventory) {}

    /** @return Builder<Coupon> */
    public function listQuery(): Builder
    {
        return Coupon::query()->with(['course:id,name_ar', 'photo'])->withCount('redemptions');
    }

    public function courseOptions(string $search = ''): array
    {
        return $this->inventory->courses()->when($search !== '', fn (Builder $query) => $query->where('name_ar', 'like', '%'.$search.'%'))
            ->orderBy('name_ar')->orderBy('id')->limit(50)->pluck('name_ar', 'id')->all();
    }

    public function courseLabel(mixed $id): ?string
    {
        return filled($id) ? ($this->inventory->courses()->whereKey($id)->value('name_ar') ?? 'ارتباط قديم غير صالح') : null;
    }
}
