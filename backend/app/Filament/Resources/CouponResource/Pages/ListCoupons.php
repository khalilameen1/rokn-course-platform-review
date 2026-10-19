<?php

namespace App\Filament\Resources\CouponResource\Pages;

use App\Filament\Resources\CouponResource;
use App\Models\Coupon;
use App\Services\AdminCouponAuthoringService;
use App\Support\CouponEditorVersion;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class ListCoupons extends ListRecords
{
    protected static string $resource = CouponResource::class;

    #[Locked]
    public ?string $pendingVersion = null;

    #[Locked]
    public ?int $pendingRecord = null;

    public function rememberVersion(Coupon $record): void
    {
        $this->pendingRecord = (int) $record->id;
        $this->pendingVersion = CouponEditorVersion::for($record);
    }

    public function removeCoupon(Coupon $record): void
    {
        abort_unless(CouponResource::canDelete($record), 403);
        abort_unless($this->pendingRecord === (int) $record->id && $this->pendingVersion !== null, 409);
        try {
            app(AdminCouponAuthoringService::class)->delete((int) $record->id, $this->pendingVersion);
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->errors()['editor_version'][0] ?? 'تعذر حذف الكود')->warning()->send();

            return;
        }
        Notification::make()->title('تم الحذف')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
