<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Models\AiInputAttachment;
use App\Models\Course;
use App\Models\User;
use App\Services\AiInputAttachmentService;
use App\Services\StoredFileReferenceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AiInputAttachmentStorageRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->softDeletes();
        });
        (require database_path('migrations/2026_09_01_000066_create_ai_input_attachments.php'))->up();
        (require database_path('migrations/2026_08_07_000022_create_account_file_deletions_table.php'))->up();
        DB::table('users')->insert(['id' => 1, 'active' => true]);
        DB::table('courses')->insert(['id' => 1]);
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('ai-input-retry');
        config(['projects.submission_disk' => 'ai-input-retry']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('account_file_deletions');
        Schema::dropIfExists('ai_input_attachments');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public static function purposes(): array
    {
        return [
            'course chat' => [AiInputAttachment::PURPOSE_COURSE_CHAT],
            'project followup' => [AiInputAttachment::PURPOSE_PROJECT_FOLLOWUP],
        ];
    }

    #[DataProvider('purposes')]
    public function test_retry_survives_a_failed_attempt_cleanup_already_past_its_reference_check(string $purpose): void
    {
        $service = app(AiInputAttachmentService::class);
        $user = User::findOrFail(1);
        $course = Course::findOrFail(1);
        $clientId = (string) Str::uuid();
        $bytes = "Course question attachment\n";
        $disk = Storage::disk('ai-input-retry');
        $failingDisk = \Mockery::mock($disk);
        $failingDisk->shouldReceive('putFileAs')->once()
            ->andReturnUsing(function ($directory, $file, $name) use ($disk): bool {
                $disk->put($directory.'/'.$name, 'partial');
                return false;
            });
        Storage::set('ai-input-retry', $failingDisk);
        try {
            $service->store($user, $course, UploadedFile::fake()->createWithContent('notes.txt', $bytes), $purpose, $clientId);
            self::fail('A failed write must not be acknowledged as an uploaded attachment.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Tracked file storage failed.', $exception->getMessage());
        } finally {
            Storage::set('ai-input-retry', $disk);
        }
        self::assertSame(0, AiInputAttachment::count());
        $cleanup = AccountFileDeletion::query()->firstOrFail();
        $failedPath = $cleanup->path;
        self::assertSame('partial', $disk->get($failedPath));
        $accepted = null;
        $interleavedDisk = \Mockery::mock($disk);
        $interleavedDisk->shouldReceive('delete')->once()->with($failedPath)
            ->andReturnUsing(function () use ($service, $user, $course, $bytes, $purpose, $clientId, $disk, $failedPath, &$accepted): bool {
                // The real worker has already checked references. A retry can
                // reserve and publish its attachment before native delete runs.
                $accepted = $service->store($user, $course,
                    UploadedFile::fake()->createWithContent('notes.txt', $bytes), $purpose, $clientId);
                return $disk->delete($failedPath);
            });
        Storage::set('ai-input-retry', $interleavedDisk);
        try {
            (new DeleteAccountFile((int) $cleanup->id))->handle(app(StoredFileReferenceService::class));
        } finally {
            Storage::set('ai-input-retry', $disk);
        }

        self::assertInstanceOf(AiInputAttachment::class, $accepted);
        self::assertSame(AiInputAttachment::READY, $accepted->fresh()->status);
        $disk->assertExists($accepted->storage_path);
        self::assertSame($bytes, $disk->get($accepted->storage_path));
        self::assertNotSame($failedPath, $accepted->storage_path);
        self::assertSame($clientId, $accepted->client_upload_id);
        self::assertSame(1, AiInputAttachment::count());
        $disk->assertMissing($failedPath);
        $replayDisk = \Mockery::mock($disk);
        $replayDisk->shouldNotReceive('putFileAs');
        Storage::set('ai-input-retry', $replayDisk);
        try {
            $replay = $service->store($user, $course,
                UploadedFile::fake()->createWithContent('notes.txt', $bytes), $purpose, $clientId);
        } finally {
            Storage::set('ai-input-retry', $disk);
        }
        self::assertSame($accepted->public_id, $replay->public_id);
        self::assertSame($accepted->storage_path, $replay->storage_path);
        self::assertSame(2, AccountFileDeletion::count());
        self::assertCount(1, $disk->allFiles());
        Http::assertNothingSent();
    }

    public function test_accepted_receipt_rejects_changed_content_course_or_purpose_without_writing(): void
    {
        $service = app(AiInputAttachmentService::class);
        $user = User::findOrFail(1);
        $course = Course::findOrFail(1);
        $clientId = (string) Str::uuid();
        $bytes = 'Original attachment';
        $accepted = $service->store($user, $course,
            UploadedFile::fake()->createWithContent('notes.txt', $bytes),
            AiInputAttachment::PURPOSE_COURSE_CHAT, $clientId);
        DB::table('courses')->insert(['id' => 2]);
        $disk = Storage::disk('ai-input-retry');
        $readOnlyDisk = \Mockery::mock($disk);
        $readOnlyDisk->shouldNotReceive('putFileAs');
        Storage::set('ai-input-retry', $readOnlyDisk);
        try {
            foreach ([
                [$course, AiInputAttachment::PURPOSE_COURSE_CHAT, 'Changed attachment'],
                [Course::findOrFail(2), AiInputAttachment::PURPOSE_COURSE_CHAT, $bytes],
                [$course, AiInputAttachment::PURPOSE_PROJECT_FOLLOWUP, $bytes],
            ] as [$requestedCourse, $purpose, $content]) {
                try {
                    $service->store($user, $requestedCourse,
                        UploadedFile::fake()->createWithContent('notes.txt', $content), $purpose, $clientId);
                    self::fail('A receipt cannot be rebound to a different attachment.');
                } catch (\UnexpectedValueException $exception) {
                    self::assertSame('AI upload id was reused for different content.', $exception->getMessage());
                }
            }
        } finally {
            Storage::set('ai-input-retry', $disk);
        }
        self::assertSame(1, AiInputAttachment::count());
        self::assertSame(1, AccountFileDeletion::count());
        self::assertSame($bytes, $disk->get($accepted->storage_path));
        Http::assertNothingSent();
    }

    public function test_another_account_cannot_adopt_an_accepted_upload_receipt(): void
    {
        DB::table('users')->insert(['id' => 2, 'active' => true]);
        $service = app(AiInputAttachmentService::class);
        $course = Course::findOrFail(1);
        $clientId = (string) Str::uuid();
        $first = $service->store(User::findOrFail(1), $course,
            UploadedFile::fake()->createWithContent('notes.txt', 'Same content'),
            AiInputAttachment::PURPOSE_COURSE_CHAT, $clientId);
        $second = $service->store(User::findOrFail(2), $course,
            UploadedFile::fake()->createWithContent('notes.txt', 'Same content'),
            AiInputAttachment::PURPOSE_COURSE_CHAT, $clientId);
        self::assertNotSame($first->public_id, $second->public_id);
        self::assertNotSame($first->storage_path, $second->storage_path);
        self::assertSame(2, (int) $second->user_id);
        self::assertSame(2, AiInputAttachment::count());
        self::assertCount(2, Storage::disk('ai-input-retry')->allFiles());
        Http::assertNothingSent();
    }
}
