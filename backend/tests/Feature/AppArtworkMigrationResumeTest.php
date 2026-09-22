<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AppArtworkMigrationResumeTest extends TestCase
{
    public function test_partial_artwork_migration_resumes_and_preserves_saved_urls(): void
    {
        Schema::create('design_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('coin_image_url', 2048)->nullable();
        });
        $url = 'https://example.test/'.str_repeat('a', 2000).'.png';
        DB::table('design_settings')->insert(['id' => 1, 'coin_image_url' => $url]);

        $migration = require database_path('migrations/2026_09_20_000001_add_app_artwork_to_design_settings.php');
        $migration->up();
        $migration->up();

        foreach (['coin', 'coin_stack', 'badge_junior', 'badge_mid', 'badge_senior'] as $key) {
            self::assertSame('text', Schema::getColumnType('design_settings', $key.'_image_url'));
        }
        self::assertSame($url, DB::table('design_settings')->where('id', 1)->value('coin_image_url'));
        self::assertNull(DB::table('design_settings')->where('id', 1)->value('badge_senior_image_url'));
    }
}
