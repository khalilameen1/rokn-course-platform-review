<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\NotificationCampaignResource;
use App\Filament\Resources\NotificationCampaignResource\Pages\CreateNotificationCampaign;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Services\AdminNotificationCampaignReadService;
use App\Services\CoursePublishingService;
use App\Support\BusinessClock;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

final class FilamentNotificationCampaignEditorWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        config(['app.url' => 'https://localhost', 'filesystems.disks.public.url' => 'https://localhost/storage']);
        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المدير', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_broadcast_requires_explicit_audience_and_review_before_queueing(): void
    {
        Livewire::test(CreateNotificationCampaign::class)->assertSet('data.audience', null)
            ->fillForm($this->fields())->call('create')->assertHasFormErrors(['audience', 'confirm_send']);
        Livewire::test(CreateNotificationCampaign::class)->fillForm([...$this->fields(), 'audience' => 'all'])
            ->call('create')->assertHasFormErrors(['confirm_send']);
        self::assertSame(0, NotificationCampaign::query()->count());
        Livewire::test(CreateNotificationCampaign::class)->fillForm([...$this->fields(), 'audience' => 'all', 'confirm_send' => true])
            ->call('create')->assertHasNoFormErrors();
        $record = NotificationCampaign::query()->sole();
        self::assertSame([], $record->user_ids);
        self::assertSame('service_notice', $record->notification_type);
        self::assertSame($record->title_ar, $record->title_en);
        self::assertFalse(NotificationCampaignResource::canEdit($record));
        self::assertFalse(NotificationCampaignResource::canDelete($record));
        Http::assertNothingSent();
    }

    public function test_schedule_artwork_and_receipt_recovery_share_the_existing_owner(): void
    {
        $sendAt = BusinessClock::now()->addDay()->format('Y-m-d\TH:i');
        $page = Livewire::test(CreateNotificationCampaign::class)->fillForm([...$this->fields(),
            'audience' => 'all', 'confirm_send' => true, 'send_at' => $sendAt,
            'image' => UploadedFile::fake()->image('campaign.png')]);
        $receipt = $page->get('creationIntent');
        $page->call('create')->assertHasNoFormErrors();
        $record = NotificationCampaign::query()->sole();
        self::assertSame('scheduled', $record->status);
        self::assertSame(BusinessClock::localInputToUtc($sendAt)->getTimestamp(), $record->scheduled_at->getTimestamp());
        self::assertNotEmpty($record->image_url);
        self::assertCount(1, Storage::disk('public')->allFiles('student-notifications'));
        Livewire::test(CreateNotificationCampaign::class)->call('restoreAuthoringDraft', [
            'version' => '', 'receipt' => $receipt, 'values' => $this->fields(),
        ])->assertReturned(['state' => 'completed', 'url' => NotificationCampaignResource::getUrl('view', ['record' => $record->id])]);
        self::assertSame(1, NotificationCampaign::query()->count());
    }

    public function test_native_field_errors_keep_missing_courses_invalid_links_and_schedule_out_of_the_queue(): void
    {
        Livewire::test(CreateNotificationCampaign::class)->fillForm([...$this->fields(), 'audience' => 'enrolled', 'confirm_send' => true])
            ->call('create')->assertHasFormErrors(['course_id']);
        Livewire::test(CreateNotificationCampaign::class)->fillForm([...$this->fields(), 'audience' => 'all', 'confirm_send' => true,
            'action_link' => 'https://other.example/pay'])->call('create')->assertHasFormErrors(['action_link']);
        Livewire::test(CreateNotificationCampaign::class)->fillForm([...$this->fields(), 'audience' => 'all', 'confirm_send' => true,
            'send_at' => BusinessClock::now()->addDays(91)->format('Y-m-d\TH:i')])->call('create')->assertHasFormErrors(['send_at']);
        self::assertSame(0, NotificationCampaign::query()->count());
    }

    public function test_test_delivery_ignores_broadcast_confirmation_and_never_targets_the_audience(): void
    {
        $tester = $this->student('tester@rokn.test');
        $this->student('other@rokn.test');
        Livewire::test(CreateNotificationCampaign::class)->fillForm($this->fields())
            ->call('sendTest', 'TESTER@rokn.test')->assertHasNoFormErrors()->assertNotified('أُضيفت التجربة إلى قائمة الإرسال')
            ->assertSet('testingDelivery', false)->assertSet('data.confirm_send', false);
        $record = NotificationCampaign::query()->sole();
        self::assertSame([(int) $tester->id], array_map('intval', $record->user_ids));
        self::assertSame('admin_message', $record->notification_type);
        self::assertStringStartsWith('تجربة: ', $record->title_ar);
        Livewire::test(CreateNotificationCampaign::class)->fillForm($this->fields())->call('sendTest', 'missing@rokn.test')
            ->assertNotified('لا يوجد حساب طالب بهذا البريد')->assertSet('testingDelivery', false);
        self::assertSame(1, NotificationCampaign::query()->count());
        Http::assertNothingSent();
    }

    public function test_individual_identity_is_locked_and_is_excluded_from_restorable_drafts(): void
    {
        $first = $this->student('first@rokn.test');
        $second = $this->student('second@rokn.test');
        $page = Livewire::test(CreateNotificationCampaign::class, ['student' => $first->id]);
        $page->call('restoreAuthoringDraft', ['version' => '', 'receipt' => (string) Str::uuid(),
            'values' => [...$this->fields(), 'user_id' => $second->id, 'confirm_send' => true]])->assertReturned(['state' => 'restored']);
        $page->call('create')->assertHasNoFormErrors();
        self::assertSame([(int) $first->id], array_map('intval', NotificationCampaign::query()->sole()->user_ids));
        self::assertSame('all', NotificationCampaign::query()->sole()->audience);
        Livewire::test(CreateNotificationCampaign::class, ['student' => auth('web')->id()])->assertNotFound();
    }

    public function test_recipient_preview_obeys_opt_out_and_aliases_preserve_individual_urls_and_permissions(): void
    {
        $student = $this->student('student@rokn.test');
        $this->student('opt-out@rokn.test', ['marketing_notifications_enabled' => false]);
        $this->student('disabled@rokn.test', ['active' => false]);
        Livewire::test(CreateNotificationCampaign::class)->fillForm(['audience' => 'all', 'notification_kind' => 'marketing'])->assertSee('1 طالب');
        Livewire::test(CreateNotificationCampaign::class)->fillForm(['audience' => 'all', 'notification_kind' => 'service'])->assertSee('2 طالب');
        $this->get(route('admin.notifications.create'))->assertRedirect(NotificationCampaignResource::getUrl('create'));
        $this->get(route('admin.notifications.create', ['user_id' => $student->id]))
            ->assertRedirect(NotificationCampaignResource::getUrl('create-for-student', ['student' => $student->id]));
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(CreateNotificationCampaign::class)->assertForbidden();
    }

    public function test_refusal_for_a_fixed_inactive_recipient_has_a_visible_notification(): void
    {
        $student = $this->student('inactive@rokn.test', ['active' => false]);
        Livewire::test(CreateNotificationCampaign::class, ['student' => $student->id])->fillForm($this->fields())
            ->call('create')->assertHasFormErrors(['user_id'])->assertNotified('فعّل حساب الطالب قبل إرسال الإشعار');
        self::assertSame(0, NotificationCampaign::query()->count());
    }

    private function student(string $email, array $extra = []): User
    {
        return User::query()->forceCreate(['name_ar' => 'الطالب', 'email' => $email, 'role' => 'client', 'active' => true,
            'marketing_notifications_enabled' => true, ...$extra]);
    }

    public function test_course_search_is_literal_and_selected_course_owns_the_destination(): void
    {
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'Design_100%', 'price' => 100,
            'is_coming_soon' => false, 'is_catalog_visible' => true]);
        Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'DesignX1000', 'price' => 100, 'is_coming_soon' => false]);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        $lesson = Lesson::query()->create(['list_id' => $course->id, 'title_ar' => 'الدرس', 'is_opened' => true]);
        CourseSection::query()->create(['course_id' => $course->id, 'module_id' => $module->id,
            'title_ar' => 'الدرس', 'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id, 'order' => 1]);
        self::assertSame([$course->id => 'Design_100%'], app(AdminNotificationCampaignReadService::class)->courseOptions('_100%'));
        $publishing = Mockery::mock(CoursePublishingService::class);
        $publishing->shouldReceive('audit')->andReturn(['ready' => true, 'issues' => []]);
        $this->app->instance(CoursePublishingService::class, $publishing);
        Livewire::test(CreateNotificationCampaign::class)->fillForm([...$this->fields(), 'audience' => 'not_enrolled',
            'course_id' => $course->id, 'confirm_send' => true, 'action_link' => 'https://irrelevant.example'])
            ->assertFormFieldIsHidden('action_link')->call('create')->assertHasNoFormErrors();
        self::assertSame('rokn://course/'.$course->id, NotificationCampaign::query()->sole()->link);
    }

    private function fields(): array
    {
        return ['title_ar' => 'إشعار التجربة الأصلي', 'message_ar' => 'نص الإشعار الأصلي', 'notification_kind' => 'service',
            'action_label' => 'افتح ركن', 'action_link' => '/home'];
    }
}
