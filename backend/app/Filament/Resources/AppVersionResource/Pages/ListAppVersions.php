<?php

namespace App\Filament\Resources\AppVersionResource\Pages;

use App\Filament\Resources\AppVersionResource;
use App\Filament\Widgets\ReleaseReadiness;
use App\Models\AppVersion;
use App\Services\AppReleaseAuthoringService;
use App\Support\AppVersionEditorVersion;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class ListAppVersions extends ListRecords
{
    protected static string $resource = AppVersionResource::class;

    #[Locked]
    public ?string $pendingVersion = null;

    #[Locked]
    public ?int $pendingRecord = null;

    public function rememberVersion(AppVersion $record): void
    {
        $this->pendingRecord = (int) $record->id;
        $this->pendingVersion = AppVersionEditorVersion::for($record);
    }

    public function changeRelease(AppVersion $record, string $operation): void
    {
        abort_unless(in_array($operation, ['toggleActive', 'delete'], true), 422);
        abort_unless($this->pendingRecord === (int) $record->id && $this->pendingVersion !== null, 409);
        abort_unless(AppVersionResource::getAuthorizationResponse($operation === 'delete' ? 'delete' : 'update', $record)->allowed(), 403);
        try {
            $writer = app(AppReleaseAuthoringService::class);
            $changed = $operation === 'delete'
                ? $writer->delete((int) $record->id, $this->pendingVersion)
                : $writer->toggleActive((int) $record->id, $this->pendingVersion);
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->errors()['editor_version'][0] ?? 'تعذر تحديث الإصدار')->warning()->send();

            return;
        }
        Notification::make()->title($changed ? 'تم تحديث الإصدار' : ($operation === 'delete' ? 'أوقف الإصدار قبل حذفه' : 'أكمل هوية الإصدار ورابط التحديث قبل تفعيله'))
            ->status($changed ? 'success' : 'warning')->send();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    protected function getHeaderWidgets(): array
    {
        return [ReleaseReadiness::class];
    }
}
