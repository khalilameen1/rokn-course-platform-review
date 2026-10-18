<?php

declare(strict_types=1);

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsTableSeeder extends Seeder
{
    /** @var list<array{key:string,value:string}> */
    protected array $settings = [
        ['key' => 'site_name_ar', 'value' => 'ركن'],
        ['key' => 'site_name_en', 'value' => 'Rokn'],
        ['key' => 'email', 'value' => ''],
        ['key' => 'phone', 'value' => ''],
        ['key' => 'currency_code', 'value' => 'EGP'],
        ['key' => 'max_hours', 'value' => '6'],
        ['key' => 'max_providers', 'value' => '6'],
        ['key' => 'seo_meta_title_ar', 'value' => 'ركن للتعلم'],
        ['key' => 'seo_meta_description_ar', 'value' => 'تعلم بخطوات قصيرة وطبّق ما تتعلمه'],
        ['key' => 'seo_meta_title_en', 'value' => 'Rokn Learning'],
        ['key' => 'seo_meta_description_en', 'value' => 'Short, practical learning journeys from Rokn'],
        ['key' => 'whatsapp', 'value' => ''],
        ['key' => 'facebook', 'value' => ''],
        ['key' => 'instagram', 'value' => ''],
        ['key' => 'twitter', 'value' => ''],
        ['key' => 'percent_type', 'value' => 'fixed'],
        ['key' => 'percent', 'value' => '5'],
        ['key' => 'max_debit', 'value' => '-100'],
    ];

    public function run(): void
    {
        foreach ($this->settings as $index => $setting) {
            if (! Setting::query()->create($setting)) {
                $this->command?->error("Settings seed failed at record {$index}.");

                return;
            }
        }

        $this->command?->info('Inserted '.count($this->settings).' settings records.');
    }
}
