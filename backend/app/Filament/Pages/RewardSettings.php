<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Support\FormValidation;
use App\Models\Setting;
use App\Services\AdminEconomyReadService;
use App\Services\AdminRewardAuthoringService;
use App\Services\SocialAuthProviderRegistry;
use App\Support\RewardConfigurationVersion;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @property-read Schema $form */
final class RewardSettings extends Page
{
    protected static ?string $title = 'إعدادات المكافآت';

    protected static string|\UnitEnum|null $navigationGroup = 'التطبيق';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'reward-settings';

    protected string $view = 'filament.pages.reward-settings';

    public array $data = [];

    #[Locked]
    public string $editorVersion;

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.coin-earning-methods.index', 'GET');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->fillStoredSettings();
    }

    private function fillStoredSettings(): void
    {
        $setting = app(AdminEconomyReadService::class)->rewardSettings();
        $this->editorVersion = RewardConfigurationVersion::settings($setting);
        $defaults = [
            'rewards_help_ar' => Setting::defaultRewardsHelp('ar'), 'rewards_help_en' => Setting::defaultRewardsHelp('en'),
            'how_to_use_coins_ar' => null, 'how_to_use_coins_en' => null, 'reward_balance_cap' => 1200,
            'max_reward_contribution_per_course' => 1200, 'max_course_promotion_percent' => config('course_plans.max_promotion_percent', 20),
            'recommended_social_provider' => config('social_auth.recommended_provider'), 'recommended_provider_bonus_coins' => 0,
            'recommended_provider_badge_ar' => config('social_auth.recommended_provider_badge_ar'),
            'recommended_provider_badge_en' => config('social_auth.recommended_provider_badge_en'),
        ];
        foreach ($defaults as $field => $default) {
            $defaults[$field] = $setting?->getAttribute($field) ?? $default;
        }
        $this->form->fill($defaults);
    }

    public function form(Schema $schema): Schema
    {
        $amount = fn (string $field): TextInput => TextInput::make($field)->required()->integer()->minValue(0)->maxValue(1000000);

        return $schema->statePath('data')->disabled(fn (): bool => ! $this->canSave())->components([
            Section::make('كيف يعمل الرصيد')->columns(2)->schema([
                Textarea::make('rewards_help_ar')->label('الشرح بالعربية')->rows(3)->maxLength(600),
                Textarea::make('rewards_help_en')->label('الشرح بالإنجليزية')->rows(3)->maxLength(600)->extraInputAttributes(['dir' => 'ltr']),
            ]),
            Section::make('حدود المكافآت والخصم')->columns(2)->schema([
                $amount('reward_balance_cap')->label('أقصى رصيد مكافآت بالعملات'),
                TextInput::make('max_course_promotion_percent')->label('أقصى خصم للمكافآت والكوبونات معًا')->suffix('٪')
                    ->required()->integer()->minValue(0)->maxValue(20)->helperText('من سعر الاشتراك ولا يقيّد العملات المشتراة'),
            ]),
            Section::make('التسجيل الموصى به')->columns(2)->schema([
                Select::make('recommended_social_provider')->label('منصة الدخول المميزة')->options(fn (): array => app(SocialAuthProviderRegistry::class)->labels())
                    ->required()->rules([Rule::in(app(SocialAuthProviderRegistry::class)->declared()->all())]),
                $amount('recommended_provider_bonus_coins')->label('عملات إضافية فوق هدية التسجيل'),
                TextInput::make('recommended_provider_badge_ar')->label('نص الشارة بالعربية')->maxLength(255),
                TextInput::make('recommended_provider_badge_en')->label('نص الشارة بالإنجليزية')->maxLength(255)->extraInputAttributes(['dir' => 'ltr']),
            ]),
            Section::make('الإصدارات السابقة والموقع')->collapsible()->collapsed()->columns(2)->schema([
                Textarea::make('how_to_use_coins_ar')->label('نص المحفظة بالعربية')->rows(4)->maxLength(12000),
                Textarea::make('how_to_use_coins_en')->label('نص المحفظة بالإنجليزية')->rows(4)->maxLength(12000)->extraInputAttributes(['dir' => 'ltr']),
                $amount('max_reward_contribution_per_course')->label('السقف القديم بالعملات وليس بالنسبة'),
            ]),
        ])->columns(1);
    }

    public function save(): void
    {
        abort_unless($this->canSave(), 403);
        $data = $this->form->getState();
        try {
            app(AdminRewardAuthoringService::class)->updateSettings($data, $this->editorVersion);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['editor_version'])) {
                throw FormValidation::forRecord($exception);
            }
            Notification::make()->title('تغيّرت إعدادات المكافآت')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();

            return;
        }
        $this->fillStoredSettings();
        Notification::make()->title('تم الحفظ')->success()->send();
    }

    public function canSave(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.coin-earning-methods.update-settings', 'POST');
    }
}
