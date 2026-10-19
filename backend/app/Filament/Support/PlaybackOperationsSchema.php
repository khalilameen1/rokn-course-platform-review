<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

/** Shared playback summary for the overview and the dedicated native monitor. */
final class PlaybackOperationsSchema
{
    public static function summary(array $snapshot, int $failureLimit = 5): array
    {
        if (! $snapshot['available']) {
            return [Section::make('غرفة تشغيل الفيديو')->schema([
                TextEntry::make('playback_unavailable')->hiddenLabel()->state('بيانات التشغيل غير متاحة قبل تطبيق migrations'),
            ])];
        }

        return [Section::make('غرفة تشغيل الفيديو')->columns(['default' => 2, 'md' => 3])->schema([
            ...collect(['active' => 'نشطة الآن', 'stale' => 'عالقة', 'completed' => 'اكتملت', 'errors' => 'بها خطأ', 'recovery_sessions' => 'احتاجت تعافيًا'])
                ->map(fn (string $label, string $key): TextEntry => TextEntry::make('playback_'.$key)->label($label)->state(number_format(data_get($snapshot, 'summary.'.$key, 0))))->values()->all(),
            ...collect(['completion_rate' => 'اكتمال الجلسات', 'error_rate' => 'معدل الأخطاء', 'buffering_rate' => 'جلسات بها تحميل مؤقت', 'average_startup_latency_ms' => 'متوسط بدء الفيديو'])
                ->map(fn (string $label, string $key): TextEntry => TextEntry::make('playback_'.$key)->label($label)->state(data_get($snapshot, 'rollup.overall.'.$key) === null
                    ? 'غير متاح' : data_get($snapshot, 'rollup.overall.'.$key).($key === 'average_startup_latency_ms' ? ' ms' : '%')))->values()->all(),
            ProductOperationsSchema::rows('playback_failures', collect($snapshot['top_failing_lessons'] ?? [])->take($failureLimit), ['lesson_title' => 'المقطع', 'course_title' => 'الكورس', 'failures' => 'أخطاء'])
                ->hiddenLabel(false)->label('أكثر المقاطع فشلًا')->columnSpanFull(),
            ProductOperationsSchema::rows('quality_mix', $snapshot['quality_mix'] ?? [], ['quality' => 'الجودة الفعلية', 'sessions' => 'الجلسات'])
                ->hiddenLabel(false)->label('توزيع الجودة الفعلية')->columnSpanFull(),
        ])];
    }

    public static function errorLabel(?string $code): string
    {
        return [
            'manifest_unreachable' => 'تعذر الوصول إلى قائمة التشغيل', 'manifest_http_error' => 'استجابة CDN غير ناجحة',
            'manifest_invalid' => 'قائمة التشغيل غير صالحة', 'provider_unreachable' => 'تعذر الوصول إلى مزود الفيديو',
            'source_timeout' => 'انتهت مهلة مصدر الفيديو', 'buffer_timeout' => 'توقف التحميل المؤقت',
            'network_error' => 'خطأ في اتصال الشبكة', 'decode_error' => 'تعذر فك الفيديو على الجهاز', 'client_error' => 'خطأ تشغيل من العميل',
        ][$code] ?? ($code ?: 'لا يوجد خطأ مسجل');
    }
}
