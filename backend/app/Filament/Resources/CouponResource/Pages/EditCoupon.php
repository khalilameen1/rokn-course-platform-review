<?php

namespace App\Filament\Resources\CouponResource\Pages;

use App\Filament\Concerns\RestoresAuthoringDraft;
use App\Filament\Resources\CouponResource;
use App\Filament\Support\FormValidation;
use App\Models\Coupon;
use App\Services\AdminCouponAuthoringService;
use App\Support\BusinessClock;
use App\Support\CouponEditorVersion;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends EditRecord<Coupon> */
final class EditCoupon extends EditRecord
{
    use RestoresAuthoringDraft;

    protected static string $resource = CouponResource::class;

    #[Locked]
    public string $editorVersion;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $coupon = $this->getRecord();
        $this->editorVersion = CouponEditorVersion::for($coupon);
        $data['starts_at'] = BusinessClock::forDateTimeInput($coupon->getAttribute('starts_at'));
        $data['expiry_date'] = substr((string) $coupon->getRawOriginal('expiry_date'), 0, 10);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $coupon = $this->getRecord();
        $image = ($data['image'] ?? null) instanceof UploadedFile ? $data['image'] : null;
        try {
            app(AdminCouponAuthoringService::class)->update($coupon, $data, $this->editorVersion, $image);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['data.code' => $exception->getMessage()]);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['editor_version'])) {
                throw FormValidation::forRecord($exception);
            }
            Notification::make()->title('تغيّر الكود أثناء التعديل')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();
            throw new Halt;
        }
        $coupon->refresh()->load('photo');
        $this->editorVersion = CouponEditorVersion::for($coupon);

        return $coupon;
    }
}
