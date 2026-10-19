<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Forms\AppSettingsSchema;
use App\Filament\Support\FormValidation;
use App\Services\AdminAppSettingsAuthoringService;
use App\Services\AdminAppSettingsReadService;
use App\Services\AdminBunnyConnectionService;
use App\Support\AppSettingsEditorVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @property-read Schema $form */
final class AppSettings extends Page
{
    protected static ?string $title = 'إعدادات التطبيق';

    protected static string|\UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.app-settings';

    public array $data = [];

    #[Locked]
    public string $editorVersion;

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.settings', 'GET');
    }

    public function canSave(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.settings.update', 'POST');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->fillStoredSettings();
    }

    private function fillStoredSettings(): void
    {
        ['settings' => $settings, 'design' => $design] = app(AdminAppSettingsReadService::class)->snapshot();
        $this->editorVersion = AppSettingsEditorVersion::for($settings, $design);
        $data = ['direct_checkout_discount_percent' => 0, 'device_login_policy' => 'multiple_devices',
            'ai_global_daily_request_limit' => config('openrouter.global_daily_request_limit', 5000),
            'ai_global_daily_token_budget' => config('openrouter.global_daily_token_budget', 2100000),
            'ai_global_monthly_token_budget' => config('openrouter.global_monthly_token_budget', 50000000),
            'ai_plan_policy' => app(AdminAppSettingsReadService::class)->defaultAiPolicy()];
        foreach (AppSettingsSchema::SETTINGS as $field) {
            $data[$field] = $settings->getAttribute($field) ?? ($data[$field] ?? null);
        }
        foreach (AppSettingsSchema::LINKS as $field) {
            $data[$field] = $design->getAttribute($field);
        }
        foreach (AppSettingsSchema::SECRETS as $field) {
            $data[$field] = null;
        }
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->disabled(fn (): bool => ! $this->canSave())->components(AppSettingsSchema::components())->columns(1);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('testBunny')->label('اختبار اتصال Bunny')->authorize(fn (): bool => $this->canTestBunny())
            ->schema([AppSettingsSchema::secret('api_key', 'مفتاح اختبار بديل'),
                TextInput::make('library_id')->label('معرّف المكتبة للاختبار')->maxLength(40)->rules(['regex:/^\d+$/'])
                    ->helperText('اترك الحقلين فارغين لاختبار الاتصال المحفوظ')])
            ->action(function (array $data): void {
                abort_unless($this->canTestBunny(), 403);
                $result = app(AdminBunnyConnectionService::class)->test($data);
                Notification::make()->title($result['message'])->status($result['success'] ? 'success' : 'warning')->send();
            })];
    }

    public function canTestBunny(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.settings.test-bunny', 'POST');
    }

    public function save(): void
    {
        abort_unless($this->canSave(), 403);
        $data = $this->form->getState();
        try {
            app(AdminAppSettingsAuthoringService::class)->update([...$data, 'editor_version' => $this->editorVersion]);
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['editor_version'])) {
                Notification::make()->title('تغيّرت إعدادات التطبيق')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();

                return;
            }
            throw FormValidation::forRecord($exception);
        }
        $this->fillStoredSettings();
        Notification::make()->title('تم الحفظ')->success()->send();
    }
}
