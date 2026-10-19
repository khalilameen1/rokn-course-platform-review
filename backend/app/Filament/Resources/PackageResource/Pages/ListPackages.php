<?php

namespace App\Filament\Resources\PackageResource\Pages;

use App\Filament\Resources\PackageResource;
use App\Models\Package;
use App\Services\AdminPackageAuthoringService;
use App\Support\PackageEditorVersion;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class ListPackages extends ListRecords
{
    protected static string $resource = PackageResource::class;

    #[Locked]
    public ?string $pendingVersion = null;

    #[Locked]
    public ?int $pendingRecord = null;

    public function rememberVersion(Package $record): void
    {
        $this->pendingRecord = (int) $record->id;
        $this->pendingVersion = PackageEditorVersion::for($record);
    }

    public function removePackage(Package $record): void
    {
        abort_unless(PackageResource::canDelete($record), 403);
        abort_unless($this->pendingRecord === (int) $record->id && $this->pendingVersion !== null, 409);
        try {
            $removed = app(AdminPackageAuthoringService::class)->deleteIfUnused((int) $record->id, $this->pendingVersion);
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->errors()['editor_version'][0] ?? 'تعذر حذف الحزمة')->warning()->send();

            return;
        }
        Notification::make()->title($removed ? 'تم الحذف' : 'الحزمة مرتبطة بمنتج متجر أو عمليات دفع')->status($removed ? 'success' : 'warning')->send();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
