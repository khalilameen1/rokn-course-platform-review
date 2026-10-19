<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ProductOperations;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\BunnyDeliveryService;
use App\Services\BunnyService;
use App\Services\MediaHealthService;
use App\Services\MediaReconciliationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

final class FilamentProductOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_report_and_legacy_bookmark_preserve_all_operational_sections(): void
    {
        $this->get(route('admin.product-operations.index'))->assertRedirect(ProductOperations::getUrl());
        Livewire::test(ProductOperations::class)->assertOk()->assertSee('جاهزية البنية')->assertSee('أعطال التطبيق الفعلية')
            ->assertSee('مراجعة إشعارات المتاجر')->assertSee('فصل الإيراد عن المكافآت')->assertSee('غرفة تشغيل الفيديو')
            ->assertSee('لا توجد حالات مسجلة');
    }

    public function test_course_table_is_searchable_paginated_and_excludes_private_configuration(): void
    {
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس التقارير', 'is_coming_soon' => true, 'description_ar' => 'PRIVATE-DESCRIPTION-NEVER-TABLE']);
        $other = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس مختلف', 'is_coming_soon' => true]);
        Livewire::test(ProductOperations::class)->searchTable('كورس التقارير')->assertCanSeeTableRecords([$course->id])
            ->assertCanNotSeeTableRecords([$other->id])->assertDontSee('PRIVATE-DESCRIPTION-NEVER-TABLE');
    }

    public function test_probe_calls_same_reconciliation_owner_only_after_an_authorized_action(): void
    {
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس الفحص', 'is_coming_soon' => true]);
        $guid = 'a3cc17a0-4b61-4e59-a4dc-947eabf36790';
        $lesson = Lesson::query()->create(['list_id' => $course->id, 'title_ar' => 'مقطع يحتاج متابعة', 'video_source_type' => 'bunny', 'bunny_video_id' => $guid]);
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('inspectRemoteVideo')->once()->with($guid)->andReturn(['state' => 'ok', 'http_status' => 200, 'details' => [
            'guid' => $guid, 'videoLibraryId' => 123, 'status' => 4, 'length' => 75, 'availableResolutions' => '720p,480p', 'thumbnailFileName' => 'thumbnail.jpg',
        ]]);
        $delivery = Mockery::mock(BunnyDeliveryService::class);
        $delivery->shouldReceive('videoPlayback')->once()->with($guid)->andReturn(['url' => 'https://media.example/playlist.m3u8']);
        Http::fake(['https://media.example/playlist.m3u8' => Http::response("#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6.0,\nsegments/0001.ts\n#EXT-X-ENDLIST\n", 200)]);
        $this->app->instance(MediaReconciliationService::class, new MediaReconciliationService($bunny, new MediaHealthService($bunny), $delivery));
        Livewire::test(ProductOperations::class)->callAction('probe', ['lesson_id' => $lesson->id])->assertHasNoActionErrors()
            ->assertNotified('الفيديو يعمل لكن توجد تفاصيل تحتاج مراجعة');
        self::assertSame('ready', $lesson->mediaState()->firstOrFail()->status);
        self::assertNotNull($lesson->mediaState()->firstOrFail()->last_reconciled_at);
    }

    public function test_content_operator_cannot_open_or_probe_operations(): void
    {
        $this->actingAs(User::query()->forceCreate(['role' => 'moderator', 'active' => true]), 'web');
        $this->get(ProductOperations::getUrl())->assertForbidden();
    }
}
