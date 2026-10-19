<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Concerns\RestoresAuthoringDraft;
use App\Filament\Forms\ArtworkSettingsSchema;
use App\Filament\Support\FormValidation;
use App\Models\DesignSetting;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminDesignSettingsAuthoringService;
use App\Services\AppArtworkService;
use App\Support\DesignSettingsEditorVersion;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @property-read Schema $form */
final class ArtworkSettings extends Page
{
    use RestoresAuthoringDraft;

    protected static ?string $title = 'صور وهوية التطبيق';

    protected static string|\UnitEnum|null $navigationGroup = 'التطبيق';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'app-artwork';

    protected string $view = 'filament.pages.artwork-settings';

    public array $data = [];

    #[Locked]
    public array $images = [];

    #[Locked]
    public string $editorVersion;

    #[Locked]
    public string $creationIntent;

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.design-settings.index', 'GET');
    }

    public function canSave(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.design-settings.store', 'POST');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->creationIntent = (string) Str::uuid();
        $this->fillStoredSettings();
    }

    private function fillStoredSettings(): void
    {
        $settings = DesignSetting::getDefaultSettings();
        $this->editorVersion = DesignSettingsEditorVersion::for($settings);
        $this->images = app(AppArtworkService::class)->urls($settings);
        foreach (['logo', 'icon', 'home_background'] as $key) {
            $this->images[$key] = $settings->getAttribute($key.'_url');
        }
        $this->form->fill(Arr::only($settings->getAttributes(), ArtworkSettingsSchema::fields()));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->disabled(fn (): bool => ! $this->canSave())->components([
            View::make('filament.forms.authoring-draft')->key('authoring-draft-recovery')->columnSpanFull(),
            ...ArtworkSettingsSchema::components(),
        ])->columns(1);
    }

    protected function authoringDraftContext(): array
    {
        return ['fields' => ArtworkSettingsSchema::fields(), 'fileFields' => ArtworkSettingsSchema::fileFields(),
            'version' => $this->editorVersion, 'receipt' => $this->creationIntent, 'canRestore' => $this->canSave(),
            'tracksReceipt' => true, 'operation' => 'filament.design-settings.store', 'model' => DesignSetting::class];
    }

    protected function completedAuthoringDraftUrl(string $recordId): string
    {
        return self::getUrl();
    }

    protected function adoptAuthoringDraftReceipt(string $receipt): void
    {
        $this->creationIntent = $receipt;
    }

    public function save(): void
    {
        abort_unless($this->canSave(), 403);
        $data = $this->form->getState();
        $files = array_filter($data, fn ($value): bool => $value instanceof UploadedFile);
        try {
            app(AdminAuthoringCreateIntentService::class)->createResource('filament.design-settings.store', $this->creationIntent,
                DesignSetting::class, [...$data, 'editor_version' => $this->editorVersion],
                fn ($complete): DesignSetting => app(AdminDesignSettingsAuthoringService::class)->save($data, $files, $this->editorVersion, $complete));
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['editor_version']) || isset($exception->errors()['authoring_request_id'])) {
                Notification::make()->title('تغيّرت إعدادات التصميم')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();

                return;
            }
            throw FormValidation::forRecord($exception);
        }
        $this->fillStoredSettings();
        $this->creationIntent = (string) Str::uuid();
        $this->acknowledgeAuthoringDraft($this->editorVersion, $this->creationIntent);
        Notification::make()->title('تم الحفظ')->success()->send();
    }
}
