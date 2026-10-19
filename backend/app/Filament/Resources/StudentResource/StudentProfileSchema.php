<?php

namespace App\Filament\Resources\StudentResource;

use App\Filament\Resources\StudentResource;
use App\Filament\Resources\StudentResource\Pages\ViewStudent;
use App\Models\User;
use App\Support\BusinessClock;
use App\Support\LearningReminderSchedule;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class StudentProfileSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('الحساب')->columns(2)->schema([
                ImageEntry::make('portrait')->label('الصورة')->alt('الصورة')->state(fn (User $record): ?string => $record->profile_image_url)
                    ->defaultImageUrl(asset('images/avatar/customer_blank.png'))->circular()->imageSize(72),
                TextEntry::make('active')->label('الحالة')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'نشط' : 'موقوف')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                TextEntry::make('id')->label('رقم الطالب')->copyable(),
                TextEntry::make('created_at')->label('التسجيل بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName()),
                TextEntry::make('email')->label('البريد')->copyable()->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('phone')->label('الهاتف')->placeholder('—')->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('social')->label('تسجيل الدخول')->state(fn (ViewStudent $livewire): string => $livewire->summary()['user']->socialAccounts
                    ->map(fn ($account): string => ['google' => 'Google', 'apple' => 'Apple', 'facebook' => 'Facebook', 'tiktok' => 'TikTok'][$account->provider] ?? $account->provider)->implode(' · '))
                    ->placeholder('لا يوجد حساب اجتماعي مرتبط')->columnSpanFull(),
                TextEntry::make('interests.name_ar')->label('الاهتمامات')->badge()->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('التعلم الحالي')->schema([
                TextEntry::make('course')->label('الكورس')->state(fn (ViewStudent $livewire): string => $livewire->summary()['learning']['course']->title ?? 'لا يوجد كورس نشط')
                    ->url(fn (User $record): ?string => StudentResource::allowsRoute('admin.student-progress.show', 'GET') ? route('admin.student-progress.show', $record) : null),
                TextEntry::make('progress')->label('التقدم')->state(fn (ViewStudent $livewire): string => number_format($livewire->summary()['learning']['progress']['progress_percentage'] ?? 0).'%'),
                TextEntry::make('active_enrollments_count')->label('الاشتراكات النشطة')->state(fn (ViewStudent $livewire): int => (int) $livewire->summary()['user']->getAttribute('active_enrollments_count')),
                TextEntry::make('portfolio_items_count')->label('أعمال الطالب')->state(fn (ViewStudent $livewire): int => (int) $livewire->summary()['user']->getAttribute('portfolio_items_count'))
                    ->url(fn (User $record): ?string => StudentResource::allowsRoute('admin.portfolio-preview.show', 'GET') ? route('admin.portfolio-preview.show', $record) : null),
            ]),
            Section::make('رصيد العملات')->columns(2)->schema([
                ...collect(['total_balance' => 'الإجمالي', 'purchased_balance' => 'مدفوع', 'reward_balance' => 'مكافآت', 'course_spendable_balance' => 'المتاح لكورس جديد'])
                    ->map(fn (string $label, string $key): TextEntry => TextEntry::make('balance_'.$key)->label($label)
                        ->state(fn (ViewStudent $livewire): int => $livewire->summary()['walletSummary'][$key])->numeric())->values()->all(),
            ]),
            Section::make('التذكير والإشعارات')->columns(2)->collapsible()->schema([
                TextEntry::make('reminder')->label('تذكير التعلم')->state(fn (User $record): string => $record->notifications_status ? 'مفعّل' : 'متوقف'),
                TextEntry::make('reminder_time')->label('الوقت')->state(fn (User $record): string => sprintf('%02d:00', LearningReminderSchedule::hour($record->learning_reminder_hour))
                    .' · '.LearningReminderSchedule::timezone($record->learning_reminder_timezone))->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('push_status')->label('الوصول للهاتف')->state(fn (ViewStudent $livewire): string => $livewire->summary()['user']->notifications_status
                    && $livewire->summary()['user']->getAttribute('device_tokens_count') > 0
                        ? 'إشعارات الهاتف مفعلة على '.$livewire->summary()['user']->getAttribute('device_tokens_count').' جهاز' : 'سيظهر الإشعار داخل حساب الطالب')->columnSpanFull(),
            ]),
        ]);
    }
}
