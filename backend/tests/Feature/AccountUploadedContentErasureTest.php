<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Models\Course;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountUploadedContentErasureService;
use App\Services\StoredFileReferenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountUploadedContentErasureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        // Real outer commits exercise cleanup dispatch, not a test transaction.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Queue::fake();
        Http::preventStrayRequests();
        foreach (['local', 'public', 'feedback', 'certificates'] as $disk) {
            Storage::fake($disk);
        }
        config(['certificate.disk' => 'certificates']);
        $this->freezeTime();
    }

    public function test_deletion_releases_all_owned_uploads_without_erasing_another_learners_content(): void
    {
        $owned = $this->fixture('owned');
        $other = $this->fixture('other');
        $otherBefore = $this->snapshot($other);

        $result = app(AccountDeletionService::class)->delete($owned['user']);

        self::assertTrue($result['local_cleanup_pending']);
        self::assertSame($otherBefore, $this->snapshot($other));
        self::assertNull(User::withTrashed()->findOrFail($owned['user']->id)->profile_image);
        foreach (['ai_input_attachments', 'feedback_reports', 'feedback_attachments', 'photos'] as $table) {
            self::assertNull(DB::table($table)->find($owned['rows'][$table]));
        }
        $submission = ProjectSubmission::query()->findOrFail($owned['rows']['project_submissions']);
        foreach (['submission_text', 'submission_file', 'original_file_name', 'mime_type', 'file_size', 'submission_metadata'] as $field) {
            self::assertNull($submission->$field);
        }
        self::assertSame('passed', $submission->review_status);
        self::assertSame(90, $submission->score);
        self::assertSame(['rubric_version' => 1], $submission->evaluation_snapshot);
        $certificate = DB::table('certificates')->find($owned['rows']['certificates']);
        self::assertSame('pending', $certificate->image_path);
        self::assertSame('revoked', $certificate->status);
        self::assertNotNull($certificate->revoked_at);
        self::assertNull($certificate->holder_name);

        $ledger = AccountFileDeletion::query()->get();
        self::assertEqualsCanonicalizing($owned['files'], $ledger->map(
            static fn (AccountFileDeletion $row): array => ['disk' => $row->disk, 'path' => $row->path]
        )->all());
        Queue::assertPushed(DeleteAccountFile::class, count($owned['files']));
        foreach ($ledger as $row) {
            self::assertSame($owned['user']->id, $row->user_id);
            // Neither the content owner nor identity deletion may remove bytes.
            Storage::disk($row->disk)->assertExists($row->path);
            (new DeleteAccountFile($row->id))->handle(app(StoredFileReferenceService::class));
            Storage::disk($row->disk)->assertMissing($row->path);
            self::assertSame(AccountFileDeletion::STATUS_COMPLETED, $row->fresh()->status);
        }
        foreach ($other['files'] as $file) {
            Storage::disk($file['disk'])->assertExists($file['path']);
        }
        Http::assertNothingSent();
    }

    public function test_identity_failure_restores_content_and_discards_cleanup_before_a_successful_retry(): void
    {
        $owned = $this->fixture('rollback');
        $before = $this->snapshot($owned);
        $failOnce = true;
        User::saving(static function (User $user) use (&$failOnce): void {
            if ($failOnce && $user->name === 'حساب محذوف') {
                $failOnce = false;
                throw new \RuntimeException('identity write failed');
            }
        });
        try {
            app(AccountDeletionService::class)->delete($owned['user']);
            self::fail('Identity and content erasure must roll back together.');
        } catch (\RuntimeException $error) {
            self::assertSame('identity write failed', $error->getMessage());
        }
        self::assertSame($before, $this->snapshot($owned));
        self::assertSame(0, AccountFileDeletion::query()->count());
        Queue::assertNothingPushed();
        foreach ($owned['files'] as $file) {
            Storage::disk($file['disk'])->assertExists($file['path']);
        }
        app(AccountDeletionService::class)->delete($owned['user']);
        self::assertNotNull(User::withTrashed()->findOrFail($owned['user']->id)->deleted_at);
        self::assertSame(count($owned['files']), AccountFileDeletion::query()->count());
        Queue::assertPushed(DeleteAccountFile::class, count($owned['files']));
    }

    public function test_external_profile_images_and_pending_certificates_are_not_disk_cleanup_targets(): void
    {
        $owned = $this->fixture('external');
        $owned['user']->forceFill(['profile_image' => 'https://example.test/avatar.jpg'])->save();
        DB::table('certificates')->where('id', $owned['rows']['certificates'])->update(['image_path' => 'pending']);
        app(AccountDeletionService::class)->delete($owned['user']);
        $paths = AccountFileDeletion::query()->get()->pluck('path')->all();
        self::assertCount(count($owned['files']) - 3, $paths);
        self::assertNotContains('https://example.test/avatar.jpg', $paths);
        self::assertNotContains('pending', $paths);
        self::assertSame('revoked', DB::table('certificates')->where('id', $owned['rows']['certificates'])->value('status'));
    }

    public function test_each_content_entry_requires_the_callers_transaction(): void
    {
        $owner = app(AccountUploadedContentErasureService::class);
        foreach ([
            static fn () => $owner->eraseLearningFilesWithinDeletion(new User(['id' => 7])),
            static fn () => $owner->eraseSupportFilesWithinDeletion(7),
            static fn () => $owner->eraseLegacyPhotosWithinDeletion(7),
        ] as $erase) {
            try {
                $erase();
                self::fail('Content erasure must not start an independent transaction.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('account-deletion transaction', $error->getMessage());
            }
        }
        self::assertSame(0, DB::transactionLevel());
        Queue::assertNothingPushed();
    }

    public function test_content_owner_returns_paths_without_taking_over_identity_ledger_or_dispatch(): void
    {
        $owned = $this->fixture('boundary');
        $owner = app(AccountUploadedContentErasureService::class);
        $identityBefore = $owned['user']->fresh()->getAttributes();
        $files = DB::transaction(function () use ($owner, $owned): array {
            $locked = User::query()->lockForUpdate()->findOrFail($owned['user']->id);
            return array_merge(
                $owner->eraseLearningFilesWithinDeletion($locked),
                $owner->eraseSupportFilesWithinDeletion($locked->id),
                $owner->eraseLegacyPhotosWithinDeletion($locked->id)
            );
        });
        self::assertEqualsCanonicalizing($owned['files'], array_values(array_unique($files, SORT_REGULAR)));
        self::assertSame($identityBefore, $owned['user']->fresh()->getAttributes());
        self::assertSame(0, AccountFileDeletion::query()->count());
        Queue::assertNothingPushed();
        foreach ($owned['files'] as $file) {
            Storage::disk($file['disk'])->assertExists($file['path']);
        }
    }

    public function test_legacy_photos_belonging_to_a_different_model_are_not_erased(): void
    {
        $owned = $this->fixture('morph');
        $photoId = DB::table('photos')->insertGetId([
            'photoable_type' => Course::class, 'photoable_id' => $owned['user']->id, 'path' => 'courses/cover.jpg',
        ]);
        Storage::disk('public')->put('courses/cover.jpg', 'Course artwork');
        app(AccountDeletionService::class)->delete($owned['user']);
        self::assertSame('courses/cover.jpg', DB::table('photos')->where('id', $photoId)->value('path'));
        self::assertNotContains('courses/cover.jpg', AccountFileDeletion::query()->get()->pluck('path')->all());
        Storage::disk('public')->assertExists('courses/cover.jpg');
    }

    private function snapshot(array $fixture): array
    {
        $snapshot = ['users' => (array) DB::table('users')->find($fixture['user']->id)];
        foreach ($fixture['rows'] as $table => $id) {
            $snapshot[$table] = (array) DB::table($table)->find($id);
        }
        return $snapshot;
    }

    /** @return array{user: User, rows: array<string, int>, files: list<array{disk: string, path: string}>} */
    private function fixture(string $key): array
    {
        $user = User::query()->forceCreate(['name' => $key, 'email' => $key.'@example.test',
            'password' => 'unused', 'role' => 'client', 'active' => true, 'profile_image' => $key.'/profile.jpg']);
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'Erasure fixture',
            'price' => 900, 'authoring_version' => 1, 'is_coming_soon' => true, 'is_catalog_visible' => false]);
        $submission = ProjectSubmission::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $user->id, 'project_id' => Project::factory()->create()->id,
            'idempotency_key' => (string) Str::uuid(), 'submitted_at' => now(),
            'submission_text' => 'Private learner text', 'submission_file' => $key.'/project.pdf',
            'original_file_name' => 'private.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
            'submission_metadata' => ['storage_disk' => 'local', 'files' => [
                ['path' => $key.'/project.pdf', 'storage_disk' => 'local'],
                ['path' => $key.'/extra.jpg', 'storage_disk' => 'public'],
                ['path' => ''], null,
            ]], 'review_status' => 'passed', 'score' => 90, 'evaluation_snapshot' => ['rubric_version' => 1],
        ]);
        $rows = ['project_submissions' => $submission->id];
        $rows['ai_input_attachments'] = DB::table('ai_input_attachments')->insertGetId([
            'public_id' => (string) Str::uuid(), 'client_upload_id' => (string) Str::uuid(),
            'user_id' => $user->id, 'course_id' => $course->id, 'purpose' => 'project_submission',
            'storage_disk' => 'local', 'storage_path' => $key.'/project.pdf',
            'original_file_name' => 'private.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 10, 'sha256' => str_repeat('a', 64),
        ]);
        $rows['certificates'] = DB::table('certificates')->insertGetId([
            'user_id' => $user->id, 'course_id' => $course->id, 'image_path' => $key.'/certificate.png',
            'generated_at' => now(), 'holder_name' => $key,
        ]);
        $rows['feedback_reports'] = DB::table('feedback_reports')->insertGetId([
            'public_id' => (string) Str::ulid(), 'user_id' => $user->id, 'category' => 'bug', 'message' => 'Private report',
        ]);
        $rows['feedback_attachments'] = DB::table('feedback_attachments')->insertGetId([
            'feedback_report_id' => $rows['feedback_reports'], 'disk' => 'feedback',
            'path' => $key.'/support.png', 'mime_type' => 'image/png', 'size_bytes' => 10,
        ]);
        $rows['photos'] = DB::table('photos')->insertGetId([
            'photoable_type' => User::class, 'photoable_id' => $user->id, 'path' => $key.'/legacy.jpg',
        ]);
        $files = [
            ['disk' => 'public', 'path' => $key.'/profile.jpg'],
            ['disk' => 'local', 'path' => $key.'/project.pdf'],
            ['disk' => 'public', 'path' => $key.'/project.pdf'],
            ['disk' => 'public', 'path' => $key.'/extra.jpg'],
            ['disk' => 'certificates', 'path' => $key.'/certificate.png'],
            ['disk' => 'public', 'path' => $key.'/certificate.png'],
            ['disk' => 'feedback', 'path' => $key.'/support.png'],
            ['disk' => 'public', 'path' => $key.'/legacy.jpg'],
        ];
        foreach ($files as $file) {
            Storage::disk($file['disk'])->put($file['path'], 'Private bytes');
        }
        return compact('user', 'rows', 'files');
    }
}
