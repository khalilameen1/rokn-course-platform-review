<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplateResource;

use App\Models\AdminNotification;
use App\Rules\KnownNotificationPlaceholders;
use App\Rules\RoknNotificationDestination;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/** Native schema adapts Rokn fields; fixed welcome policy still belongs to the model/writer. */
final class NotificationTemplateSchema
{
    public static function welcome(?AdminNotification $record): bool
    {
        return $record?->system_key === 'guest_registration_prompt';
    }

    public static function components(): array
    {
        $copyRules = [new KnownNotificationPlaceholders];

        return [
            Hidden::make('system_key')->dehydrateStateUsing(fn (?AdminNotification $record): ?string => $record?->system_key),
            Section::make('النص والوجهة')->description('المتغيرات المتاحة {coins} {course} {task} {lesson} {project} {case}')->columns(2)->schema([
                TextEntry::make('event_identity')->label('الحدث')->state(fn (?AdminNotification $record): string => $record?->system_key ?: 'إعلان يدوي'),
                Select::make('surface')->label('مكان الاستخدام')->options(AdminNotification::SURFACES)->default('announcement')->required()
                    ->disabled(fn (?AdminNotification $record): bool => ! $record?->isSystemTemplate() || self::welcome($record))->dehydrated()
                    ->dehydrateStateUsing(fn ($state, ?AdminNotification $record) => self::welcome($record) ? 'guest_prompt' : ($record?->isSystemTemplate() ? $state : 'announcement')),
                TextInput::make('title_ar')->label('العنوان بالعربية')->required()->minLength(3)->maxLength(80)->rules($copyRules),
                TextInput::make('title_en')->label('العنوان بالإنجليزية')->minLength(3)->maxLength(80)->rules($copyRules)->extraInputAttributes(['dir' => 'ltr']),
                Textarea::make('description_ar')->label('النص بالعربية')->required(fn (?AdminNotification $record): bool => ! self::welcome($record))
                    ->minLength(3)->maxLength(240)->rows(3)->rules($copyRules)
                    ->helperText(fn (?AdminNotification $record): ?string => self::welcome($record) ? 'اختياري ولا يظهر في بطاقة الهدية' : null),
                Textarea::make('description_en')->label('النص بالإنجليزية')->minLength(3)->maxLength(240)->rows(3)->rules($copyRules)->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('action_label_ar')->label('نص الزر بالعربية')->maxLength(80)
                    ->required(fn (Get $get, ?AdminNotification $record): bool => $get('is_active') && (self::welcome($record) || filled($get('link')))),
                TextInput::make('action_label_en')->label('نص الزر بالإنجليزية')->maxLength(80)->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('secondary_action_label_ar')->label('نص الإغلاق بالعربية')->maxLength(80)
                    ->required(fn (Get $get, ?AdminNotification $record): bool => $get('is_active') && self::welcome($record)),
                TextInput::make('secondary_action_label_en')->label('نص الإغلاق بالإنجليزية')->maxLength(80)->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('link')->label('الوجهة داخل التطبيق')->maxLength(2000)->placeholder('/wallet أو /course/52')->rules([new RoknNotificationDestination])
                    ->visible(fn (?AdminNotification $record): bool => ! self::welcome($record))
                    ->required(fn (Get $get, ?AdminNotification $record): bool => $get('is_active') && ! self::welcome($record) && filled($get('action_label_ar')))
                    ->extraInputAttributes(['dir' => 'ltr'])->columnSpanFull(),
                TextEntry::make('welcome_policy')->label('هدية الترحيب')->visible(fn (?AdminNotification $record): bool => self::welcome($record))
                    ->state('تظهر مرة واحدة على الجهاز وتفتح تسجيل الدخول — العدد من مكافأة الترحيب والصورة الافتراضية من صور التطبيق')->columnSpanFull(),
                TextEntry::make('coin_offer_policy')->label('اقتراح المهام')->visible(fn (?AdminNotification $record): bool => $record?->system_key === 'coin_offer')
                    ->state('يظهر قبل محاولة الدفع فقط عند وجود مكافأة قابلة للاستخدام — النص والتصميم ثابتان في التطبيق وإيقاف القالب يوقف الاقتراح')->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('الظهور والتوقيت')->columns(2)->schema([
                Toggle::make('is_active')->label('مفعّل')->default(true)->live(),
                Toggle::make('is_dismissible')->label('يمكن للطالب إغلاقه')->default(true)->disabled(fn (?AdminNotification $record): bool => self::welcome($record))->dehydrated()
                    ->dehydrateStateUsing(fn ($state, ?AdminNotification $record) => self::welcome($record) ? true : $state),
                TextInput::make('priority')->label('الأولوية')->helperText('الرقم الأقل يظهر أولًا')->required()->integer()->minValue(0)->maxValue(1000)->default(100)
                    ->disabled(fn (?AdminNotification $record): bool => self::welcome($record))->dehydrated()
                    ->dehydrateStateUsing(fn ($state, ?AdminNotification $record) => self::welcome($record) ? 10 : $state),
                TextInput::make('cooldown_hours')->label('الساعات بين الظهورين')->required()->integer()->minValue(0)->maxValue(8760)->default(72)
                    ->disabled(fn (?AdminNotification $record): bool => self::welcome($record))->dehydrated()
                    ->dehydrateStateUsing(fn ($state, ?AdminNotification $record) => self::welcome($record) ? 0 : $state),
                TextInput::make('starts_at')->label('يبدأ بتوقيت القاهرة')->type('datetime-local')->rules(['nullable', 'date']),
                TextInput::make('ends_at')->label('ينتهي بتوقيت القاهرة')->type('datetime-local')
                    ->rules(fn (Get $get): array => ['nullable', 'date', ...(filled($get('starts_at')) ? ['after:data.starts_at'] : [])]),
            ])->columnSpanFull(),
            Section::make('الصورة')->columns(2)->schema([
                ImageEntry::make('current_image')->label('الصورة الحالية')->hiddenOn('create')->imageHeight(144)->state(fn (?AdminNotification $record): ?string => $record?->photo?->assetPath()),
                FileUpload::make('image')->label('صورة بديلة')->image()->rules(['image'])->maxSize(4096)->disk('public')->visibility('public')->storeFiles(false),
                Toggle::make('remove_image')->label('حذف الصورة الحالية')->visible(fn (?AdminNotification $record): bool => $record?->photo !== null)->default(false),
            ])->columnSpanFull(),
        ];
    }
}
