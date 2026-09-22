<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AppArtworkMysqlStorageTest extends TestCase
{
    public function test_all_artwork_urls_fit_the_real_design_settings_schema(): void
    {
        if (!filter_var(env('ROKN_REQUIRE_MYSQL_CONTRACT_TEST'), FILTER_VALIDATE_BOOL)) {
            self::markTestSkipped('Requires the production MySQL schema contract job.');
        }
        self::assertSame('testing', app()->environment());
        self::assertSame('mysql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/(?:^|_)test(?:_|$)/i', DB::connection()->getDatabaseName());

        $urls = [];
        foreach (['coin', 'coin_stack', 'badge_junior', 'badge_mid', 'badge_senior'] as $key) {
            $column = $key.'_image_url';
            self::assertSame('text', Schema::getColumnType('design_settings', $column));
            $urls[$column] = str_pad('https://example.test/'.$key.'/', 2044, 'a').'.png';
        }
        DB::beginTransaction();
        try {
            $id = DB::table('design_settings')->insertGetId([
                'name_ar' => 'رُكن', 'name_en' => 'Rokn', ...$urls,
            ]);
            $saved = DB::table('design_settings')->where('id', $id)->first();
            foreach ($urls as $column => $url) {
                self::assertSame($url, $saved->{$column});
            }
        } finally {
            DB::rollBack();
        }
    }
}
