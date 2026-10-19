<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use App\Filament\Pages\ArtworkSettings;
use App\Services\AppArtworkService;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

final class ArtworkSettingsSchema
{
    public static function fields(): array
    {
        return ['name_ar', 'name_en', 'slogan_1_ar', 'slogan_1_en', 'slogan_2_ar', 'slogan_2_en', 'slogan_3_ar', 'slogan_3_en',
            'color_1', 'color_2', 'color_3', 'color_4', 'show_how_platform_works', 'how_platform_works_title_ar', 'how_platform_works_title_en', 'how_platform_works_video_link'];
    }

    public static function fileFields(): array
    {
        return ['logo_file', 'icon_file', 'home_background_file', ...array_map(fn (string $key): string => $key.'_image_file', array_keys(AppArtworkService::ASSETS))];
    }

    public static function components(): array
    {
        $copy = fn (string $field, string $label): TextInput => TextInput::make($field)->label($label)->maxLength(255);
        $pictures = [
            self::picture('logo', 'شعار المنصة', 1524), self::picture('icon', 'أيقونة الموقع', 1524),
            self::picture('home_background', 'خلفية الرئيسية', 2048),
        ];
        foreach (AppArtworkService::ASSETS as $key => $asset) {
            $pictures[] = self::picture($key, $asset['label'], 4096, true);
        }

        return [
            Section::make('اسم ونصوص المنصة')->columns(2)->schema([
                $copy('name_ar', 'الاسم بالعربية')->required(), $copy('name_en', 'الاسم بالإنجليزية')->required()->extraInputAttributes(['dir' => 'ltr']),
                ...array_merge(...array_map(fn (int $index): array => [
                    $copy('slogan_'.$index.'_ar', 'الشعار النصي '.$index.' بالعربية'),
                    $copy('slogan_'.$index.'_en', 'الشعار النصي '.$index.' بالإنجليزية')->extraInputAttributes(['dir' => 'ltr']),
                ], [1, 2, 3])),
            ])->collapsible()->collapsed()->columnSpanFull(),
            ...$pictures,
            Section::make('الألوان')->columns(2)->schema(array_map(fn (string $field, string $label): ColorPicker => ColorPicker::make($field)
                ->label($label)->required()->rules(['regex:/^#[0-9A-Fa-f]{6}$/'])->extraInputAttributes(['dir' => 'ltr']),
                ['color_1', 'color_2', 'color_3', 'color_4'], ['اللون الأساسي', 'اللون الثانوي', 'اللون المحايد', 'اللون المميز']))->collapsible()->collapsed()->columnSpanFull(),
            Section::make('فيديو التعريف في الموقع')->columns(2)->schema([
                Toggle::make('show_how_platform_works')->label('إظهار الفيديو')->live()->columnSpanFull(),
                $copy('how_platform_works_title_ar', 'العنوان بالعربية'),
                $copy('how_platform_works_title_en', 'العنوان بالإنجليزية')->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('how_platform_works_video_link')->label('رابط YouTube أو Vimeo')->url()->rules(['starts_with:https://'])->maxLength(2048)
                    ->required(fn (Get $get): bool => (bool) $get('show_how_platform_works'))->extraInputAttributes(['dir' => 'ltr'])->columnSpanFull(),
            ])->collapsible()->collapsed()->columnSpanFull(),
        ];
    }

    private static function picture(string $key, string $label, int $maxSize, bool $artwork = false): Section
    {
        $file = $key.($artwork ? '_image_file' : '_file');

        return Section::make($label)->columns(2)->schema([
            ImageEntry::make($key.'_preview')->label('الصورة الحالية')->imageHeight(112)->placeholder('لم تُرفع صورة')
                ->state(fn (ArtworkSettings $livewire): ?string => $livewire->images[$key] ?? null),
            FileUpload::make($file)->label('صورة بديلة — '.$label)->image()->rules(['image', ...($artwork ? ['dimensions:max_width=4096,max_height=4096'] : [])])
                ->acceptedFileTypes($artwork ? ['image/png', 'image/webp'] : ['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize($maxSize)->disk('public')->visibility('public')->storeFiles(false),
        ])->collapsible()->collapsed(! $artwork)->columnSpanFull();
    }
}
