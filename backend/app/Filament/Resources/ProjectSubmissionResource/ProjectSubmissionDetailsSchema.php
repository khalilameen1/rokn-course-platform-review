<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectSubmissionResource;

use App\Filament\Resources\ProjectSubmissionResource;
use App\Filament\Resources\ProjectSubmissionResource\Pages\ViewProjectSubmission;
use App\Models\ProjectSubmission;
use App\Services\AdminProjectSubmissionReviewReadService;
use App\Support\BusinessClock;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ProjectSubmissionDetailsSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('بيانات المحاولة')->columns(2)->schema([
                TextEntry::make('review_status')->label('المراجعة')->badge()->formatStateUsing(fn (string $state): string => AdminProjectSubmissionReviewReadService::STATUSES[$state] ?? $state),
                TextEntry::make('effort_status')->label('حالة التسليم')->formatStateUsing(fn (string $state): string => ['valid' => 'صالح للمراجعة', 'invalid' => 'غير كافٍ', 'unknown' => 'لم يُفحص بعد'][$state] ?? $state),
                TextEntry::make('student')->label('الطالب')->state(fn (ProjectSubmission $record): string => ProjectSubmissionResource::administrator() ? ($record->user?->getFilamentName() ?: 'حساب محذوف') : 'هوية مخفية'),
                TextEntry::make('email')->label('البريد')->state(fn (ProjectSubmission $record): ?string => $record->user?->email)->visible(fn (): bool => ProjectSubmissionResource::administrator()),
                TextEntry::make('course')->label('الكورس')->state(fn (ProjectSubmission $record): string => $record->project?->section?->course?->title ?: 'غير متاح'),
                TextEntry::make('submitted_at')->label('الإرسال بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName()),
                TextEntry::make('public_id')->label('رقم المحاولة')->copyable()->extraAttributes(['dir' => 'ltr'])->columnSpanFull(),
                TextEntry::make('submission_text')->label('النص المرسل')->placeholder('لا يوجد نص مرفق')->columnSpanFull(),
                self::attachments('files')->label('ملفات المشروع')->state(fn (ViewProjectSubmission $livewire): array => $livewire->files())->columnSpanFull()
                    ->placeholder(fn (ProjectSubmission $record): string => in_array($record->review_status, ['passed', 'needs_resubmission'], true) ? 'حُذفت ملفات التسليم بعد اكتمال المراجعة' : 'لا يوجد ملف مرفق'),
                TextEntry::make('old_attempt')->hiddenLabel()->state('هذه محاولة سابقة — راجع المحاولة الأحدث لاتخاذ القرار')
                    ->visible(fn (ViewProjectSubmission $livewire): bool => ! $livewire->details()['isLatestAttempt'])->columnSpanFull(),
            ]),
            Section::make('سجل القرار')->columns(2)->schema([
                TextEntry::make('review_source')->label('مصدر القرار')->placeholder('لم يصدر قرار بعد')
                    ->formatStateUsing(fn (string $state): string => ['admin_manual' => 'مراجعة بشرية', 'ai' => 'تقييم رُكن', 'effort_guard' => 'فحص كفاية التسليم', 'graceful_fallback' => 'عبور تلقائي عند تعذّر التقرير'][$state] ?? 'قرار مسجل'),
                TextEntry::make('reviewer.name')->label('المراجع')->placeholder('مراجعة آلية أو غير محدد'),
                TextEntry::make('reviewed_at')->label('القرار بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->placeholder('—'),
                TextEntry::make('score')->label('النتيجة')->suffix('/100')->placeholder('—'),
                TextEntry::make('feedback')->label('الملاحظة المسجلة')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('تقرير رُكن ومحادثة المشروع')->visible(fn (ViewProjectSubmission $livewire): bool => (bool) $livewire->details()['submissionState']['report_enabled'])->schema([
                TextEntry::make('report_status')->label('حالة التقرير')->badge()->state(fn (ViewProjectSubmission $livewire): string => $livewire->details()['submissionState']['report_status'])
                    ->formatStateUsing(fn (string $state): string => ['not_included' => 'غير متاح في هذه الفئة', 'not_requested' => 'يصدر بعد قبول المشروع', 'queued' => 'قيد الإعداد', 'ready' => 'جاهز', 'failed' => 'تعذّر إصداره'][$state] ?? 'غير معروف'),
                TextEntry::make('feedback_level')->label('المتابعة')->state(fn (ViewProjectSubmission $livewire): string => $livewire->details()['submissionState']['feedback_level'])
                    ->formatStateUsing(fn (string $state): string => ['pass_only' => 'عبور فقط', 'report' => 'تقرير', 'enhanced' => 'تقرير ومتابعة'][$state] ?? $state),
                RepeatableEntry::make('conversation')->hiddenLabel()->state(fn (ViewProjectSubmission $livewire): array => $livewire->conversation())->columns(3)->schema([
                    TextEntry::make('kind')->hiddenLabel()->badge(), TextEntry::make('state')->hiddenLabel(),
                    TextEntry::make('at')->label('بتوقيت القاهرة')->extraAttributes(['dir' => 'ltr']),
                    TextEntry::make('body')->hiddenLabel()->columnSpanFull(),
                    self::attachments('attachments')->hiddenLabel()->columnSpanFull()->visible(fn (RepeatableEntry $component): bool => filled($component->getState())),
                    TextEntry::make('usage')->label('استهلاك AI')->visible(fn (TextEntry $component): bool => ProjectSubmissionResource::administrator() && filled($component->getState()))->columnSpanFull(),
                ])->placeholder('لم يصدر تقرير بعد')->columnSpanFull(),
            ])->columns(2)->columnSpanFull(),
        ]);
    }

    private static function attachments(string $name): RepeatableEntry
    {
        return RepeatableEntry::make($name)->schema([
            TextEntry::make('name')->label('تنزيل الملف')->color('primary')->icon('heroicon-o-arrow-down-tray')
                ->url(fn (TextEntry $component): ?string => data_get($component->getContainer()->getConstantState(), 'url')),
            TextEntry::make('details')->hiddenLabel()->extraAttributes(['dir' => 'ltr']),
        ]);
    }
}
