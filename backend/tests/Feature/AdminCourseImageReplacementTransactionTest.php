<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AdminCourseImageReplacementTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tracked uploads must reserve their cleanup row before bytes are
        // written, so this test intentionally has no enclosing test transaction.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Storage::fake('public');
        Queue::fake([DeleteAccountFile::class]);
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public function test_cleanup_preparation_failure_cannot_commit_a_cover_then_delete_its_bytes(): void
    {
        [$moderator, $course, $oldPath] = $this->courseWithCover();
        $oldPathHash = hash('sha256', $oldPath);
        DB::statement(<<<SQL
            CREATE TRIGGER reject_old_cover_cleanup
            BEFORE INSERT ON account_file_deletions
            WHEN NEW.path_hash = '{$oldPathHash}'
            BEGIN
                SELECT RAISE(ABORT, 'cleanup ledger unavailable');
            END
        SQL);

        $this->actingAs($moderator, 'web')
            ->withHeader('Accept', 'application/json')
            ->post(route('admin.courses.update', $course), [
                '_method' => 'PATCH',
                'authoring_version' => 1,
                'publishing_intent' => 'save',
                'image' => UploadedFile::fake()->image('new-cover.png', 640, 360),
            ])
            ->assertStatus(500)
            ->assertJsonPath('status', 'save_failed');

        $course->refresh();
        self::assertSame(1, (int) $course->authoring_version);
        self::assertSame([$oldPath], $course->allPhotos()->pluck('path')->all());
        Storage::disk('public')->assertExists($oldPath);
        self::assertFalse(AccountFileDeletion::query()
            ->where('path_hash', $oldPathHash)
            ->exists());
    }

    public function test_successful_cover_replacement_commits_cleanup_ledger_before_dispatch(): void
    {
        [$moderator, $course, $oldPath] = $this->courseWithCover();

        $this->actingAs($moderator, 'web')
            ->withHeader('Accept', 'application/json')
            ->post(route('admin.courses.update', $course), [
                '_method' => 'PATCH',
                'authoring_version' => 1,
                'publishing_intent' => 'save',
                'image' => UploadedFile::fake()->image('new-cover.png', 640, 360),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'updated');

        $course->refresh();
        $newPath = (string) $course->allPhotos()->sole()->path;
        self::assertNotSame($oldPath, $newPath);
        self::assertSame(2, (int) $course->authoring_version);
        Storage::disk('public')->assertExists($oldPath);
        Storage::disk('public')->assertExists($newPath);

        $cleanup = AccountFileDeletion::query()
            ->where('path_hash', hash('sha256', $oldPath))
            ->firstOrFail();
        self::assertSame(AccountFileDeletion::STATUS_PENDING, $cleanup->status);
        Queue::assertPushed(
            DeleteAccountFile::class,
            static fn (DeleteAccountFile $job): bool => $job->deletionId === (int) $cleanup->id
        );
    }

    /** @return array{User,Course,string} */
    private function courseWithCover(): array
    {
        $moderator = new User();
        $moderator->forceFill([
            'name_ar' => 'محرر المحتوى',
            'email' => 'cover-editor@example.test',
            'role' => 'moderator',
            'active' => true,
        ])->save();

        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'مسودة بصورة',
            'description_ar' => 'وصف',
            'price' => 0,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
            'authoring_version' => 1,
            'certificate_text_template_key' => 'completion',
        ])->save();
        $oldPath = 'courses/old-cover.webp';
        $course->allPhotos()->create([
            'path' => $oldPath,
            'type' => 'featured',
        ]);
        Storage::disk('public')->put($oldPath, 'old-cover-bytes');

        return [$moderator, $course, $oldPath];
    }
}
