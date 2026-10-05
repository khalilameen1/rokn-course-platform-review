<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Exercise the real migrations against SQLite's indexed-column rollback. */
final class MigrationRollbackIndexTest extends TestCase
{
    public function test_course_publication_removes_its_revision_index_before_the_column(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_coming_soon')->default(false);
            $table->unsignedBigInteger('authoring_version')->default(1);
            $table->timestamp('updated_at')->nullable();
        });

        $migration = require database_path('migrations/2026_09_01_000072_add_publication_revision_to_courses.php');
        for ($round = 0; $round < 2; $round++) {
            $migration->up();
            self::assertTrue(Schema::hasColumn('courses', 'published_at'));
            self::assertTrue(Schema::hasIndex('courses', 'courses_last_published_authoring_version_index'));
            $migration->down();
            self::assertFalse(Schema::hasColumn('courses', 'published_at'));
            self::assertFalse(Schema::hasColumn('courses', 'last_published_authoring_version'));
            self::assertFalse(Schema::hasIndex('courses', 'courses_last_published_authoring_version_index'));
            self::assertTrue(Schema::hasColumn('courses', 'authoring_version'));
        }
    }

    public function test_notification_delivery_removes_its_scheduling_index_before_the_column(): void
    {
        Schema::create('notification_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->unsignedInteger('inbox_count')->default(0);
        });

        $migration = require database_path('migrations/2026_09_01_000079_make_notification_delivery_resumable.php');
        $migration->up();
        self::assertTrue(Schema::hasColumn('notification_campaigns', 'scheduled_at'));
        self::assertTrue(Schema::hasIndex('notification_campaigns', 'notification_campaigns_scheduled_at_index'));
        self::assertTrue(Schema::hasTable('notification_campaign_recipients'));
        self::assertTrue(Schema::hasTable('notification_push_deliveries'));

        $migration->down();
        self::assertFalse(Schema::hasColumn('notification_campaigns', 'scheduled_at'));
        self::assertFalse(Schema::hasIndex('notification_campaigns', 'notification_campaigns_scheduled_at_index'));
        self::assertTrue(Schema::hasColumn('notification_campaigns', 'retry_count'));
        self::assertFalse(Schema::hasTable('notification_campaign_recipients'));
        self::assertFalse(Schema::hasTable('notification_push_deliveries'));

        $migration->up();
        self::assertTrue(Schema::hasIndex('notification_campaigns', 'notification_campaigns_scheduled_at_index'));
        $migration->down();
        self::assertFalse(Schema::hasColumn('notification_campaigns', 'scheduled_at'));
    }
}
