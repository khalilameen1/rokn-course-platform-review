<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

final class AppSettingsSchema
{
    public const SETTINGS = ['site_name_ar', 'site_name_en', 'email', 'phone', 'direct_checkout_discount_percent',
        'seo_meta_title_ar', 'seo_meta_description_ar', 'seo_meta_title_en', 'seo_meta_description_en', 'english_translation',
        'device_login_policy', 'bunny_enabled', 'bunny_library_id', 'bunny_cdn_hostname', 'bunny_storage_zone_name',
        'support_whatsapp_url', 'ai_global_daily_request_limit', 'ai_global_daily_token_budget', 'ai_global_monthly_token_budget', 'ai_plan_policy'];

    public const LINKS = ['facebook_url', 'youtube_url', 'instagram_url', 'tiktok_url', 'telegram_url', 'whatsapp_url'];

    public const SECRETS = ['bunny_api_key', 'bunny_storage_password', 'bunny_security_key'];

    public static function secret(string $field, string $label): TextInput
    {
        return TextInput::make($field)->label($label)->password()->autocomplete('new-password')->maxLength(4096)
            ->placeholder('اتركه فارغًا للاحتفاظ بالمحفوظ')->extraInputAttributes(['dir' => 'ltr']);
    }

    public static function components(): array
    {
        $copy = fn (string $field, string $label): TextInput => TextInput::make($field)->label($label)->maxLength(255);
        $integer = fn (string $field, string $label, int $min, int $max): TextInput => TextInput::make($field)->label($label)->integer()->required()->minValue($min)->maxValue($max);

        return [
            Section::make('عام')->columns(2)->schema([
                $copy('site_name_ar', 'اسم الموقع ولوحة التحكم بالعربية'), $copy('site_name_en', 'اسم الموقع ولوحة التحكم بالإنجليزية')->extraInputAttributes(['dir' => 'ltr']),
                $copy('email', 'بريد التواصل')->email(), $copy('phone', 'هاتف التواصل')->maxLength(20)->extraInputAttributes(['dir' => 'ltr']),
                Toggle::make('english_translation')->label('تفعيل الترجمة الإنجليزية'),
                TextInput::make('direct_checkout_discount_percent')->label('خصم الدفع المباشر')->suffix('٪')->required()->numeric()->minValue(0)->maxValue(50),
                Select::make('device_login_policy')->label('دخول الأجهزة')->required()->options(['multiple_devices' => 'أجهزة متعددة', 'single_device' => 'جهاز واحد', 'single_device_permanent' => 'جهاز واحد ثابت']),
                $copy('support_whatsapp_url', 'واتساب الدعم')->helperText('رقم دولي أو رابط wa.me')->extraInputAttributes(['dir' => 'ltr']),
            ])->columnSpanFull(),
            Section::make('روابط التواصل')->columns(2)->collapsible()->collapsed()->schema([
                ...array_map(fn (string $field, string $label): TextInput => $copy($field, $label)->maxLength(2048)->url()->rules(['starts_with:https://'])->extraInputAttributes(['dir' => 'ltr']),
                    array_slice(self::LINKS, 0, 5), ['Facebook', 'YouTube', 'Instagram', 'TikTok', 'Telegram']),
                $copy('whatsapp_url', 'WhatsApp')->maxLength(2048)->helperText('رقم دولي أو رابط wa.me')->extraInputAttributes(['dir' => 'ltr']),
            ])->columnSpanFull(),
            Section::make('محركات البحث')->columns(2)->collapsible()->collapsed()->schema([
                $copy('seo_meta_title_ar', 'عنوان الصفحة بالعربية'), $copy('seo_meta_title_en', 'عنوان الصفحة بالإنجليزية'),
                Textarea::make('seo_meta_description_ar')->label('الوصف بالعربية')->rows(3)->maxLength(500),
                Textarea::make('seo_meta_description_en')->label('الوصف بالإنجليزية')->rows(3)->maxLength(500)->extraInputAttributes(['dir' => 'ltr']),
            ])->columnSpanFull(),
            Section::make('Bunny للفيديو')->columns(2)->collapsible()->collapsed()->schema([
                Toggle::make('bunny_enabled')->label('تفعيل Bunny')->columnSpanFull(),
                TextInput::make('bunny_library_id')->label('معرّف مكتبة الفيديو')->maxLength(40)->rules(['regex:/^\d+$/'])->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('bunny_cdn_hostname')->label('اسم نطاق CDN')->maxLength(253)->rules(['regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'])->extraInputAttributes(['dir' => 'ltr']),
                self::secret('bunny_api_key', 'مفتاح مكتبة الفيديو'), $copy('bunny_storage_zone_name', 'Storage Zone'),
                self::secret('bunny_storage_password', 'Storage Password'), self::secret('bunny_security_key', 'Token Authentication Key'),
            ])->columnSpanFull(),
            Section::make('تنبيهات تكلفة الشات')->description('حدود للتنبيه المبكر وليست لإيقاف الطلاب الدافعين')->columns(3)->collapsible()->collapsed()->schema([
                $integer('ai_global_daily_request_limit', 'طلبات يومية', 1, 10000000),
                $integer('ai_global_daily_token_budget', 'توكنز يومية', 1000, 1000000000),
                $integer('ai_global_monthly_token_budget', 'توكنز شهرية', 1000, 10000000000),
            ])->columnSpanFull(),
            Section::make('مزايا الاشتراكات الجديدة')->description('يمكن خفض الحدود ولا يمكن تجاوز سقف الخادم')->collapsible()->collapsed()->schema([
                Section::make('Basic')->description('للمشاهدة فقط دون تدريب أو مشاريع أو شهادة')->schema(array_map(fn (string $key, $value): Hidden => Hidden::make('ai_plan_policy.basic.'.$key)->default($value),
                    ['chat_enabled', 'chat_message_limit', 'chat_attachments_enabled', 'project_feedback_level', 'project_followup_message_limit'], [false, 0, false, 'pass_only', 0])),
                ...array_map(fn (string $code, string $label): Section => Section::make($label)->columns(2)->schema([
                    Toggle::make('ai_plan_policy.'.$code.'.chat_enabled')->label('شات ركن'),
                    $integer('ai_plan_policy.'.$code.'.chat_message_limit', 'عدد الرسائل', 0, (int) config('course_plans.ai_tiers.'.$code.'.chat_message_limit', 0)),
                    Toggle::make('ai_plan_policy.'.$code.'.chat_attachments_enabled')->label('مرفقات الشات'),
                    Select::make('ai_plan_policy.'.$code.'.project_feedback_level')->label('تقييم المشروع')->required()
                        ->options($code === 'mentor' ? ['pass_only' => 'عبور فقط', 'report' => 'تقرير', 'enhanced' => 'تدريب أعمق'] : ['pass_only' => 'عبور فقط', 'report' => 'تقرير']),
                    $integer('ai_plan_policy.'.$code.'.project_followup_message_limit', 'رسائل متابعة المشروع', 0, (int) config('course_plans.ai_tiers.'.$code.'.project_followup_message_limit', 0)),
                ]), ['guided', 'mentor'], ['Plus', 'Pro']),
            ])->columnSpanFull(),
        ];
    }
}
