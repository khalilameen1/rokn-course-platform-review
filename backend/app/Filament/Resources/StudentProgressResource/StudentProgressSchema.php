<?php

namespace App\Filament\Resources\StudentProgressResource;

use App\Filament\Resources\StudentProgressResource\Pages\ViewStudentProgress;
use App\Models\User;
use App\Support\BusinessClock;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class StudentProgressSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الطالب')->columns(3)->schema([
                TextEntry::make('name')->label('الاسم')->state(fn (User $record): string => $record->getFilamentName()),
                TextEntry::make('email')->label('البريد')->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('phone')->label('الهاتف')->placeholder('—')->extraAttributes(['dir' => 'ltr']),
            ])->columnSpanFull(),
            Section::make('الملخص')->columns(['default' => 2, 'md' => 4])->schema([
                ...collect(['courses' => 'الكورسات النشطة', 'percentage' => 'متوسط التقدم', 'completed' => 'الأقسام المكتملة', 'sections' => 'إجمالي الأقسام'])
                    ->map(fn (string $label, string $key): TextEntry => TextEntry::make('total_'.$key)->label($label)
                        ->state(fn (ViewStudentProgress $livewire): string => number_format($livewire->totals()[$key]).($key === 'percentage' ? '%' : '')))->values()->all(),
            ])->columnSpanFull(),
            Section::make('تفاصيل الكورسات')->schema([
                RepeatableEntry::make('courses')->hiddenLabel()->state(fn (ViewStudentProgress $livewire): array => $livewire->courses())->columns(2)->schema([
                    TextEntry::make('title')->label('الكورس')->columnSpanFull(),
                    TextEntry::make('percentage')->label('التقدم')->badge(), TextEntry::make('ratio')->label('الأقسام المكتملة'),
                    TextEntry::make('enrolled_at')->label('الالتحاق بتوقيت القاهرة')->dateTime('Y-m-d')->timezone(BusinessClock::timezoneName()),
                    TextEntry::make('last_activity')->label('آخر نشاط بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->placeholder('—'),
                    TextEntry::make('types')->label('حسب النوع')->columnSpanFull(),
                    RepeatableEntry::make('sections')->label('الأقسام')->columns(3)->schema([
                        TextEntry::make('title')->label('العنوان')->columnSpanFull(),
                        TextEntry::make('kind')->label('النوع'), TextEntry::make('is_completed')->label('الحالة')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'مكتمل' : 'قيد التقدم')->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                        TextEntry::make('completed_at')->label('الاكتمال بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())
                            ->visible(fn (TextEntry $component): bool => filled($component->getState())),
                    ])->columnSpanFull()->placeholder('لا توجد أقسام'),
                ])->placeholder('لا توجد كورسات نشطة'),
            ])->columnSpanFull(),
        ]);
    }
}
