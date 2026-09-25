<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminNotificationsController;
use App\Models\AdminNotification;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminNotificationTemplateAuthoringService;
use App\Support\BusinessClock;
use App\Support\NotificationTemplateEditorVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminNotificationTemplateOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('public');
        foreach ([AdminNotificationsController::class, AdminAuthoringCreateIntentService::class] as $adapter) {
            $this->app->bind($adapter, static function (): never {
                throw new \LogicException('Template authoring must not resolve HTTP adapters.');
            });
        }
    }

    public function test_template_copy_schedule_link_and_photo_commit_with_the_receipt(): void
    {
        $input = [...$this->payload(), 'starts_at' => '2026-09-01 12:00:00', 'ends_at' => '2026-09-02 12:00:00'];
        $template = app(AdminNotificationTemplateAuthoringService::class)->create(
            $input, (string) Str::uuid(), UploadedFile::fake()->image('template.png', 24, 24),
            static function (AdminNotification $template): void {
                self::assertSame(1, DB::transactionLevel());
                self::assertSame(1, $template->allPhotos()->count());
                self::assertTrue($template->fresh()->is_active);
            }
        );
        $template->refresh();
        self::assertSame($input['title_ar'], $template->title_en);
        self::assertSame($input['description_ar'], $template->description_en);
        self::assertSame('rokn://wallet', $template->link);
        self::assertSame(BusinessClock::localInputToUtc($input['starts_at'])->getTimestamp(), $template->starts_at->getTimestamp());
        self::assertSame(BusinessClock::localInputToUtc($input['ends_at'])->getTimestamp(), $template->ends_at->getTimestamp());
        Http::assertNothingSent();
    }

    public function test_create_replay_reuses_image_and_rejects_changed_copy_or_image(): void
    {
        $writer = app(AdminNotificationTemplateAuthoringService::class);
        $initialCount = AdminNotification::query()->count();
        $requestId = (string) Str::uuid();
        $image = UploadedFile::fake()->image('template.png', 24, 24);
        $template = $writer->create($this->payload(), $requestId, $image, static function (): void {});
        $photoPath = $template->allPhotos()->sole()->path;
        $again = $writer->create($this->payload(), $requestId, $image, static function (): void {});
        self::assertSame($template->id, $again->id);
        self::assertSame([$photoPath], $template->allPhotos()->pluck('path')->all());
        foreach (['copy', 'image', 'missing-image'] as $changed) {
            try {
                $writer->create(
                    $changed === 'copy' ? [...$this->payload(), 'title_ar' => 'عنوان مختلف'] : $this->payload(),
                    $requestId,
                    match ($changed) {
                        'image' => UploadedFile::fake()->image('different.png', 40, 40),
                        'missing-image' => null,
                        default => $image,
                    },
                    static function (): never { self::fail('Changed replay must not complete a receipt.'); }
                );
                self::fail('A create identity must not accept a different payload.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('authoring_request_id', $error->errors());
            }
        }
        self::assertSame($initialCount + 1, AdminNotification::query()->count());
        self::assertSame(1, AdminNotification::query()->where('authoring_request_id', $requestId)->count());
        self::assertSame([$photoPath], $template->allPhotos()->pluck('path')->all());
    }

    public function test_receipt_failure_does_not_leave_an_active_template_or_photo(): void
    {
        $requestId = (string) Str::uuid();
        $image = UploadedFile::fake()->image('retry.png', 24, 24);
        $writer = app(AdminNotificationTemplateAuthoringService::class);
        try {
            $writer->create($this->payload(), $requestId, $image, static function (): never {
                DB::table('admin_singleton_locks')->insert(['lock_key' => 'template-receipt']);
                throw new \RuntimeException('Receipt failed');
            });
            self::fail('Template and receipt must roll back together.');
        } catch (\RuntimeException $error) {
            self::assertSame('Receipt failed', $error->getMessage());
        }
        self::assertFalse(AdminNotification::query()->where('authoring_request_id', $requestId)->exists());
        self::assertSame(0, DB::table('photos')->where('photoable_type', AdminNotification::class)->count());
        self::assertFalse(DB::table('admin_singleton_locks')->where('lock_key', 'template-receipt')->exists());
        $template = $writer->create($this->payload(), $requestId, $image, static function (): void {});
        self::assertSame(1, $template->allPhotos()->count());
    }

    public function test_update_and_delete_reject_stale_photo_version_without_losing_current_content(): void
    {
        $writer = app(AdminNotificationTemplateAuthoringService::class);
        $template = $writer->create($this->payload(), (string) Str::uuid(), null, static function (): void {});
        $stale = NotificationTemplateEditorVersion::for($template->fresh());
        $writer->update((int) $template->id, $this->payload(), $stale, UploadedFile::fake()->image('new.png', 24, 24), false);
        self::assertNotSame($stale, NotificationTemplateEditorVersion::for($template->fresh()));
        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    $writer->update((int) $template->id, [...$this->payload(), 'title_ar' => 'لا يحفظ'], $stale, null, true);
                } else {
                    $writer->deleteOrDisable((int) $template->id, $stale);
                }
                self::fail('A stale editor cannot '.$operation.' the template.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('editor_version', $error->errors());
            }
        }
        self::assertSame($this->payload()['title_ar'], $template->fresh()->title_ar);
        self::assertSame(1, $template->allPhotos()->count());
        $writer->update((int) $template->id, $this->payload(), NotificationTemplateEditorVersion::for($template->fresh()), null, true);
        self::assertSame(0, $template->allPhotos()->count());
    }

    public function test_system_template_key_is_immutable_and_deletion_only_disables_it(): void
    {
        $writer = app(AdminNotificationTemplateAuthoringService::class);
        $input = [...$this->payload(), 'system_key' => 'course_completed', 'surface' => 'transactional'];
        $template = AdminNotification::query()->where('system_key', 'course_completed')->sole();
        $writer->update((int) $template->id, $input, NotificationTemplateEditorVersion::for($template), null, false);
        $writer->update((int) $template->id, [...$input, 'system_key' => 'certificate_ready'], NotificationTemplateEditorVersion::for($template->fresh()), null, false);
        self::assertSame('course_completed', $template->fresh()->system_key);
        self::assertTrue($writer->deleteOrDisable((int) $template->id, NotificationTemplateEditorVersion::for($template->fresh())));
        self::assertNotNull($template->fresh());
        self::assertFalse($template->fresh()->is_active);
        $manual = $writer->create($this->payload(), (string) Str::uuid(), null, static function (): void {});
        self::assertFalse($writer->deleteOrDisable((int) $manual->id, NotificationTemplateEditorVersion::for($manual->fresh())));
        self::assertNull(AdminNotification::find($manual->id));
    }

    private function payload(): array
    {
        return ['system_key' => null, 'surface' => 'announcement', 'title_ar' => 'رصيدك جاهز',
            'title_en' => '', 'description_ar' => 'شاهد رصيدك من العملات', 'description_en' => '',
            'link' => 'https://rokn.app/wallet', 'action_label_ar' => 'افتح الرصيد',
            'priority' => 20, 'cooldown_hours' => 0, 'is_active' => true, 'is_dismissible' => true];
    }
}
