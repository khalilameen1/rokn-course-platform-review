<?php

namespace App\Filament\Resources\CouponResource\Pages;

use App\Filament\Concerns\UsesAuthoringCreateReceipt;
use App\Filament\Resources\CouponResource;
use App\Models\Coupon;
use App\Services\AdminCouponAuthoringService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/** @extends CreateRecord<Coupon> */
final class CreateCoupon extends CreateRecord
{
    use UsesAuthoringCreateReceipt;

    protected static string $resource = CouponResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $image = ($data['image'] ?? null) instanceof UploadedFile ? $data['image'] : null;

        return $this->createWithReceipt(CouponResource::draftOperation(), Coupon::class, $data,
            fn ($complete): Coupon => app(AdminCouponAuthoringService::class)->create($data, $this->creationIntent, $image, $complete));
    }

    protected function getRedirectUrl(): string
    {
        return CouponResource::getUrl();
    }
}
