<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeleteAccountFile;
use App\Jobs\SendStudentNotification;
use App\Models\AccountFileDeletion;
use App\Models\NotificationCampaign;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminNotificationCampaignAuthoringService;
use App\Services\StoredFileReferenceService;
use App\Support\PublicDiskUrl;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\API\ApiTestCase;

final class AdminNotificationImageRetryTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '2026_09_01_000010_create_notification_campaigns_table.php',
            '2026_09_01_000076_create_admin_authoring_create_intents_table.php',
            '2026_09_01_000081_make_admin_authoring_create_intents_replayable.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::table('notification_campaigns', function (Blueprint $table): void {
            $table->timestamp('scheduled_at')->nullable();
        });
        Storage::fake('public', ['url' => 'https://rokn.test/storage']);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('admin_authoring_create_intents');
        Schema::dropIfExists('notification_campaigns');
        parent::tearDown();
    }

    public function test_failed_author_retry_keeps_its_image_when_old_cleanup_already_passed_the_reference_check(): void
    {
        $intentId = (string) Str::uuid();
        $request = $this->imageRequest($intentId);
        $intents = app(AdminAuthoringCreateIntentService::class);
        $claim = $intents->claim($request);
        self::assertIsArray($claim);
        $request->attributes->set(AdminAuthoringCreateIntentService::CLAIM_ATTRIBUTE, $claim);
        DB::statement("CREATE TRIGGER reject_notification_receipt BEFORE UPDATE ON admin_authoring_create_intents
            WHEN NEW.status = 'completed' BEGIN SELECT RAISE(ABORT, 'receipt unavailable'); END");
        try {
            $this->author($request);
            self::fail('A rejected receipt must roll back the notification campaign.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('receipt unavailable', $exception->getMessage());
            $intents->fail($request, $claim);
        } finally {
            DB::statement('DROP TRIGGER reject_notification_receipt');
        }
        self::assertSame(0, NotificationCampaign::query()->count());
        Queue::assertNotPushed(SendStudentNotification::class);
        $cleanup = AccountFileDeletion::query()->firstOrFail();
        $failedPath = (string) $cleanup->path;
        $disk = Storage::disk('public');
        $disk->assertExists($failedPath);
        $retried = null;

        $observedDisk = \Mockery::mock($disk);
        $observedDisk->shouldReceive('delete')->once()->with($failedPath)
            ->andReturnUsing(function () use ($disk, $intents, $intentId, $failedPath, &$retried): bool {
                // The actual cleanup job has already checked the old path's
                // references. Commit the real retry before its byte deletion.
                // This controls a storage interleaving, not SQL concurrency.
                $retry = $this->imageRequest($intentId);
                $retryClaim = $intents->claim($retry);
                self::assertIsArray($retryClaim);
                $retry->attributes->set(AdminAuthoringCreateIntentService::CLAIM_ATTRIBUTE, $retryClaim);
                $retried = $this->author($retry);

                return $disk->delete($failedPath);
            });
        Storage::set('public', $observedDisk);
        try {
            (new DeleteAccountFile((int) $cleanup->id))->handle(app(StoredFileReferenceService::class));
        } finally {
            Storage::set('public', $disk);
        }

        self::assertInstanceOf(NotificationCampaign::class, $retried);
        $currentPath = PublicDiskUrl::pathFrom($retried->image_url);
        self::assertNotNull($currentPath);
        $disk->assertExists($currentPath);
        self::assertSame($this->imageBytes(), $disk->get($currentPath));
        self::assertNotSame($failedPath, $currentPath);
        $disk->assertMissing($failedPath);
        self::assertSame(AccountFileDeletion::STATUS_COMPLETED, $cleanup->fresh()->status);
        $this->assertDatabaseHas('admin_authoring_create_intents', [
            'intent_id' => $intentId, 'status' => 'completed',
            'resource_type' => NotificationCampaign::class, 'resource_id' => (string) $retried->id,
        ]);
        self::assertSame(1, NotificationCampaign::query()->count());
        Queue::assertPushed(SendStudentNotification::class, 1);
        self::assertSame($retried->id, $this->author($this->imageRequest($intentId))->id);
        Queue::assertPushed(SendStudentNotification::class, 1);
        self::assertSame([$currentPath], $disk->files('student-notifications'));
    }

    #[DataProvider('imageReplayCases')]
    public function test_replay_preserves_receipt_and_image_identity_without_uploading_or_queueing_twice(
        bool $legacyPath,
        bool $individual
    ): void {
        $intentId = (string) Str::uuid();
        $request = $this->imageRequest($intentId, individual: $individual);
        $intents = app(AdminAuthoringCreateIntentService::class);
        self::assertIsArray($intents->claim($request));
        $campaign = $this->author($request);
        $disk = Storage::disk('public');
        $path = PublicDiskUrl::pathFrom($campaign->image_url);
        self::assertNotNull($path);
        if ($legacyPath) {
            $identity = ($individual ? 'notification-user|' : 'notification-campaign|') . $campaign->delivery_key;
            $oldPath = 'student-notifications/' . hash('sha256', $identity . '|' . hash('sha256', $this->imageBytes())) . '.png';
            if ($path !== $oldPath) {
                self::assertTrue($disk->move($path, $oldPath));
                $campaign->forceFill(['image_url' => PublicDiskUrl::from($oldPath)])->save();
                $path = $oldPath;
            }
        }

        $sameRequest = $this->imageRequest($intentId, individual: $individual);
        $receiptReplay = $intents->claim($sameRequest);
        self::assertSame(302, $receiptReplay->getStatusCode());
        self::assertSame(route('admin.notifications.index'), $receiptReplay->headers->get('Location'));
        // Also exercise the service's image matcher directly, rather than
        // allowing the completed HTTP receipt to short-circuit this check.
        $replay = $this->author($sameRequest);
        self::assertSame($campaign->id, $replay->id);
        self::assertSame($campaign->delivery_key, $replay->delivery_key);
        self::assertSame($campaign->image_url, $replay->image_url);
        $withoutImage = $this->imageRequest($intentId, individual: $individual);
        $withoutImage->files->remove('image');
        self::assertSame($campaign->id, $this->author($withoutImage)->id);
        $changedImage = $this->imageRequest($intentId, $this->imageBytes() . 'different content', $individual);
        try {
            $this->author($changedImage);
            self::fail('The same authoring identity cannot adopt a different image.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('authoring_request_id', $exception->errors());
        }
        self::assertSame(1, NotificationCampaign::query()->count());
        self::assertSame(1, AccountFileDeletion::query()->count());
        self::assertSame([$path], $disk->files('student-notifications'));
        self::assertSame($this->imageBytes(), $disk->get($path));
        Queue::assertPushed(SendStudentNotification::class, 1);
        $this->assertDatabaseHas('admin_authoring_create_intents', [
            'intent_id' => $intentId, 'status' => 'completed',
            'resource_type' => NotificationCampaign::class, 'resource_id' => (string) $campaign->id,
        ]);
        if (!$legacyPath) {
            $cleanup = AccountFileDeletion::query()->firstOrFail();
            $cleanup->forceFill(['available_at' => now()->subMinute()])->save();
            (new DeleteAccountFile((int) $cleanup->id))->handle(app(StoredFileReferenceService::class));
            self::assertSame(AccountFileDeletion::STATUS_SKIPPED, $cleanup->fresh()->status);
            $disk->assertExists($path);
        }
    }

    public static function imageReplayCases(): array
    {
        return [
            'current broadcast' => [false, false],
            'current individual' => [false, true],
            'legacy broadcast' => [true, false],
            'legacy individual' => [true, true],
        ];
    }

    public function test_a_committed_notification_without_an_image_cannot_gain_one_on_replay(): void
    {
        $intentId = (string) Str::uuid();
        $request = $this->imageRequest($intentId);
        $request->files->remove('image');
        $campaign = $this->author($request);
        self::assertNull($campaign->image_url);
        try {
            $this->author($this->imageRequest($intentId));
            self::fail('An accepted payload cannot acquire an image on replay.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('authoring_request_id', $exception->errors());
        }
        self::assertSame(1, NotificationCampaign::query()->count());
        self::assertSame([], Storage::disk('public')->files('student-notifications'));
        Queue::assertPushed(SendStudentNotification::class, 1);
    }

    private function author(Request $request): NotificationCampaign
    {
        return app(AdminNotificationCampaignAuthoringService::class)->author($request, $request->except('image'));
    }

    private function imageRequest(string $intentId, ?string $bytes = null, bool $individual = false): Request
    {
        $upload = UploadedFile::fake()->createWithContent('notice.png', $bytes ?? $this->imageBytes());
        $request = Request::create('/admin/notifications', 'POST', [
            'title_ar' => 'تحديث مهم', 'message_ar' => 'محتوى الإشعار',
            'audience' => 'all', 'notification_kind' => 'service',
            'authoring_request_id' => $intentId,
        ], [], ['image' => new UploadedFile($upload->getPathname(), 'notice.png', 'image/png', null, true)]);
        $request->attributes->set('test_upload_owner', $upload);
        if ($individual) {
            $request->merge(['user_id' => $this->user->id]);
        }
        $request->setUserResolver(fn () => $this->user);
        $request->setLaravelSession(app('session')->driver());
        $route = (new Route('POST', '/admin/notifications', []))->name('admin.notifications.store');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    private function imageBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2uoAAAAASUVORK5CYII=', true);
    }
}
