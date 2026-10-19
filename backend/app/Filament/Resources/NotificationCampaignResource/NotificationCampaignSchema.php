<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationCampaignResource;

use App\Filament\Resources\NotificationCampaignResource\Pages\CreateNotificationCampaign;
use App\Rules\RoknNotificationDestination;
use App\Services\AdminNotificationCampaignAuthoringService;
use App\Services\AdminNotificationCampaignReadService;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

final class NotificationCampaignSchema
{
    public static function components(): array
    {
        return [
            Section::make('الجمهور')->columns(2)->schema([
                TextEntry::make('student')->label('الطالب')->visible(fn (CreateNotificationCampaign $livewire): bool => $livewire->targetStudentId !== null)
                    ->state(fn (CreateNotificationCampaign $livewire): string => $livewire->studentLabel())->columnSpanFull(),
                Select::make('course_id')->label('الكورس')->searchable()->getSearchResultsUsing(fn (string $search): array => app(AdminNotificationCampaignReadService::class)->courseOptions($search))
                    ->getOptionLabelUsing(fn ($value): ?string => $value ? app(AdminNotificationCampaignReadService::class)->courseName((int) $value) : null)
                    ->options(fn (): array => app(AdminNotificationCampaignReadService::class)->courseOptions(''))->live()->rules(['integer'])->exists('courses', 'id')
                    ->required(fn (Get $get, CreateNotificationCampaign $livewire): bool => ! $livewire->testingDelivery && $livewire->targetStudentId === null && filled($get('audience')) && $get('audience') !== 'all'),
                Select::make('audience')->label('الجمهور')->options(['all' => 'كل الطلاب', 'enrolled' => 'المسجلون في الكورس', 'not_enrolled' => 'غير المسجلين في الكورس'])
                    ->required(fn (CreateNotificationCampaign $livewire): bool => ! $livewire->testingDelivery && $livewire->targetStudentId === null)->live()
                    ->visible(fn (CreateNotificationCampaign $livewire): bool => $livewire->targetStudentId === null),
                Select::make('notification_kind')->label('نوع الإشعار العام')->options(['marketing' => 'جديد وعروض — لمن فعّلها', 'service' => 'خدمة أو حساب — يصل للجميع'])
                    ->default('marketing')->required()->live()->visible(fn (Get $get, CreateNotificationCampaign $livewire): bool => $livewire->targetStudentId === null && blank($get('course_id'))),
            ])->columnSpanFull(),
            Section::make('محتوى الإشعار')->columns(2)->schema([
                TextInput::make('title_ar')->label('العنوان بالعربية')->required()->maxLength(80),
                TextInput::make('action_label')->label('نص الزر')->maxLength(40)->placeholder('ابدأ الكورس'),
                Textarea::make('message_ar')->label('النص بالعربية')->required()->maxLength(240)->rows(3)->columnSpanFull(),
                TextInput::make('action_link')->label('الوجهة داخل التطبيق')->maxLength(2000)->rules([new RoknNotificationDestination])
                    ->visible(fn (Get $get): bool => blank($get('course_id')))
                    ->extraInputAttributes(['dir' => 'ltr'])->helperText('فارغة تفتح الرئيسية')->columnSpanFull(),
                TextEntry::make('course_destination')->label('الوجهة')->state('صفحة الكورس المختار')
                    ->visible(fn (Get $get): bool => filled($get('course_id')))->columnSpanFull(),
                FileUpload::make('image')->label('صورة الإشعار')->image()->rules(['image'])->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(4096)->disk('public')->visibility('public')->storeFiles(false)->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('النص الإنجليزي')->collapsible()->collapsed()->schema([
                TextInput::make('title_en')->label('العنوان بالإنجليزية')->maxLength(80)->extraInputAttributes(['dir' => 'ltr']),
                Textarea::make('message_en')->label('النص بالإنجليزية')->maxLength(240)->rows(3)->extraInputAttributes(['dir' => 'ltr']),
            ])->columnSpanFull(),
            Section::make('المراجعة والإرسال')->schema([
                TextInput::make('send_at')->label('موعد الإرسال بتوقيت القاهرة')->type('datetime-local')->rules(['nullable', 'date_format:Y-m-d\TH:i'])
                    ->helperText('فارغ للإرسال الآن — خلال ٩٠ يومًا'),
                TextEntry::make('recipient_preview')->label('المستلمون الآن')->visible(fn (CreateNotificationCampaign $livewire): bool => $livewire->targetStudentId === null)
                    ->state(function (Get $get): string {
                        $audience = $get('audience');
                        $courseId = $get('course_id') ? (int) $get('course_id') : null;
                        if (! $audience || ($audience !== 'all' && ! $courseId)) {
                            return 'اختر الجمهور والكورس لعرض العدد';
                        }

                        return (string) app(AdminNotificationCampaignAuthoringService::class)->previewRecipientCount($courseId, $audience, $get('notification_kind')).' طالب — يُراجع العدد عند الإرسال';
                    }),
                Checkbox::make('confirm_send')->label('راجعت الجمهور والنص وأؤكد الإرسال')->default(false)
                    ->visible(fn (CreateNotificationCampaign $livewire): bool => $livewire->targetStudentId === null)
                    ->rules(fn (CreateNotificationCampaign $livewire): array => $livewire->targetStudentId === null && ! $livewire->testingDelivery ? ['accepted'] : []),
            ])->columnSpanFull(),
        ];
    }
}
