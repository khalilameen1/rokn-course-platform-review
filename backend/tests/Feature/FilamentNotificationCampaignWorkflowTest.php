<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\NotificationCampaignResource;
use App\Filament\Resources\NotificationCampaignResource\Pages\ListNotificationCampaigns;
use App\Filament\Resources\NotificationCampaignResource\Pages\ViewNotificationCampaign;
use App\Http\Middleware\RequireAdminMfa;
use App\Jobs\SendStudentNotification;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignRecipient;
use App\Models\StudentNotification;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentNotificationCampaignWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المدير', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_list_filters_literal_search_and_keeps_delivery_counts_in_the_read_projection(): void
    {
        $campaign = $this->campaign(['title_ar' => 'Design_100%', 'status' => NotificationCampaign::STATUS_FAILED]);
        $other = $this->campaign(['title_ar' => 'DesignX1000', 'status' => NotificationCampaign::STATUS_COMPLETED]);
        $student = User::query()->forceCreate(['name_ar' => 'طالب', 'role' => 'client', 'active' => true]);
        StudentNotification::query()->create(['user_id' => $student->id, 'delivery_key' => $campaign->delivery_key,
            'notification_type' => 'service_notice', 'title_ar' => 'عنوان', 'title_en' => 'Title', 'message_ar' => 'نص', 'message_en' => 'Body', 'is_read' => true,
            'push_attempted_at' => now(), 'push_sent_at' => now(), 'push_failed_at' => now()]);
        DB::enableQueryLog();
        $page = Livewire::test(ListNotificationCampaigns::class)->searchTable('_100%')->filterTable('status', NotificationCampaign::STATUS_FAILED)
            ->assertCanSeeTableRecords([$campaign])->assertCanNotSeeTableRecords([$other]);
        $record = $page->instance()->getTableRecords()->first();
        self::assertSame(1, $record->getAttribute('attempted_count'));
        self::assertSame(1, $record->getAttribute('provider_accepted_count'));
        self::assertSame(1, $record->getAttribute('read_count'));
        self::assertSame(0, $record->getAttribute('push_failed_count'));
        self::assertSame(1, $record->getAttribute('push_partial_count'));
        foreach (DB::getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        $this->get(route('admin.notifications.index'))->assertRedirect(NotificationCampaignResource::getUrl());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_native_cancel_uses_the_existing_owner_and_never_cancels_a_partly_delivered_campaign(): void
    {
        $scheduled = $this->campaign(['status' => NotificationCampaign::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()]);
        $page = Livewire::test(ViewNotificationCampaign::class, ['record' => $scheduled->id])
            ->callAction('cancel')->assertNotified('أُلغيت الحملة ولن تُرسل');
        self::assertSame(NotificationCampaign::STATUS_CANCELLED, $scheduled->fresh()->status);
        $page->assertActionHidden('cancel')->assertSee('أُلغيت قبل الإرسال');
        $partial = $this->campaign(['status' => NotificationCampaign::STATUS_QUEUED]);
        NotificationCampaignRecipient::query()->create(['notification_campaign_id' => $partial->id, 'user_id' => auth('web')->id(), 'status' => 'inbox']);
        Livewire::test(ListNotificationCampaigns::class)->callTableAction('cancel', $partial)->assertNotified('تغيّرت حالة الحملة');
        self::assertSame(NotificationCampaign::STATUS_QUEUED, $partial->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_retry_preserves_delivery_identity_and_dispatches_only_once(): void
    {
        $failed = $this->campaign(['status' => NotificationCampaign::STATUS_FAILED, 'failure_code' => 'queue_fixture']);
        Livewire::test(ListNotificationCampaigns::class)->callTableAction('retry', $failed)->assertNotified('أُعيدت الحملة إلى قائمة الإرسال');
        self::assertSame($failed->delivery_key, $failed->fresh()->delivery_key);
        self::assertSame(NotificationCampaign::STATUS_QUEUED, $failed->fresh()->status);
        Livewire::test(ListNotificationCampaigns::class)->assertTableActionHidden('retry', $failed->fresh());
        Queue::assertPushed(SendStudentNotification::class, 1);
    }

    public function test_stale_confirmation_cannot_cancel_an_already_started_distribution(): void
    {
        $campaign = $this->campaign(['status' => NotificationCampaign::STATUS_SCHEDULED]);
        $page = Livewire::test(ListNotificationCampaigns::class)->mountTableAction('cancel', $campaign);
        $campaign->update(['status' => NotificationCampaign::STATUS_DELIVERING, 'selection_cursor' => 3]);
        $page->callMountedTableAction();
        self::assertSame(NotificationCampaign::STATUS_DELIVERING, $campaign->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_details_escape_the_copy_and_do_not_expose_provider_internal_failure_codes(): void
    {
        $campaign = $this->campaign(['title_ar' => '<script>bad()</script>', 'status' => NotificationCampaign::STATUS_FAILED,
            'message_ar' => 'النص الكامل للحملة', 'failure_code' => 'chunk_private_provider_trace', 'link' => 'rokn://course/123']);
        Livewire::test(ViewNotificationCampaign::class, ['record' => $campaign->id])->assertSee('النص الكامل للحملة')
            ->assertSee('توقف توزيع الحملة قبل اكتماله')->assertSee('الكورس')->assertDontSee('chunk_private_provider_trace')
            ->assertDontSeeHtml('<script>bad()</script>');
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'محرر', 'role' => 'moderator', 'active' => true]), 'web');
        Livewire::test(ListNotificationCampaigns::class)->assertForbidden();
        Livewire::test(ViewNotificationCampaign::class, ['record' => $campaign->id])->assertForbidden();
        self::assertFalse(NotificationCampaignResource::allows('retry'));
    }

    private function campaign(array $overrides = []): NotificationCampaign
    {
        return NotificationCampaign::query()->create(['delivery_key' => 'native-'.Str::uuid(), 'notification_type' => 'service_notice',
            'audience' => 'all', 'title_ar' => 'حملة الاختبار', 'message_ar' => 'نص الإشعار', 'user_ids' => [], 'exclude_user_ids' => [],
            'status' => NotificationCampaign::STATUS_QUEUED, ...$overrides]);
    }
}
