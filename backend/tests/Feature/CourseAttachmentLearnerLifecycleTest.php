<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CoursePdf;
use App\Models\Lesson;
use App\Models\User;
use App\Services\CoursePublishingService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CourseAttachmentLearnerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;
    private CoursePdf $original;
    private CourseStagedAuthoringService $revisions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'course_pdfs.disk' => 'learner-attachments',
            'filesystems.disks.learner-attachments' => [
                'driver' => 'local', 'root' => storage_path('app/learner-attachments'),
                'visibility' => 'private',
            ],
        ]);
        Storage::fake('learner-attachments');
        // Publication readiness is covered separately. Clone, publish, lineage,
        // entitlement and the signed HTTP routes remain real here.
        $publishing = Mockery::mock(CoursePublishingService::class);
        $publishing->shouldReceive('audit')->andReturn(['ready' => true, 'issues' => []]);
        $this->revisions = new CourseStagedAuthoringService($publishing);
        $this->course = (new Course())->forceFill([
            'tenant_id' => 1, 'name_ar' => 'كورس الملفات', 'description_ar' => 'وصف',
            'price' => 0, 'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => 4, 'last_published_authoring_version' => 4,
            'published_at' => now(),
        ]);
        $this->course->save();
        $module = $this->course->modules()->create(['title_ar' => 'الوحدة', 'order' => 1]);
        $lesson = Lesson::create(['list_id' => $this->course->id, 'title_ar' => 'درس']);
        $this->course->sections()->create([
            'module_id' => $module->id,
            'section_type' => 'lesson', 'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id, 'order' => 1,
        ]);
        $user = (new User())->forceFill([
            'name_ar' => 'متعلم', 'email' => 'learner-files@example.test',
            'role' => 'client', 'active' => true,
        ]);
        $user->save();
        (new CourseEnrollment())->forceFill([
            'tenant_id' => 1,
            'course_id' => $this->course->id, 'user_id' => $user->id,
            'is_active' => true, 'expires_at' => now()->addDay(),
        ])->save();
        $this->actingAs($user, 'api');
        Storage::disk('learner-attachments')->put('original.txt', 'original bytes');
        $this->original = $this->course->pdfs()->create([
            'title' => 'ملف الدرس', 'source_type' => 'upload', 'platform' => 'mobile',
            'file_path' => 'original.txt', 'storage_disk' => 'learner-attachments',
            'original_filename' => 'original.txt', 'file_extension' => 'txt',
            'mime_type' => 'text/plain', 'file_size' => 14, 'is_active' => true,
        ]);
    }

    public function test_original_signed_link_and_refresh_id_follow_two_publications_without_exposing_the_draft(): void
    {
        $before = $this->metadata($this->original->id);
        $draft = $this->revisions->draftFor($this->course);
        $draft->pdfs()->firstOrFail()->forceFill([
            'source_type' => 'external', 'platform' => 'computer',
            'external_url' => 'https://files.example.test/large.zip',
            'file_path' => '', 'storage_disk' => null, 'original_filename' => null,
            'file_extension' => null, 'mime_type' => null, 'file_size' => null,
        ])->save();
        self::assertSame('upload', $this->metadata($this->original->id)['source_type']);
        $this->get($before['download_url'])->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->publish($draft);

        $external = $this->metadata($this->original->id);
        self::assertNotSame($before['id'], $external['id']);
        self::assertSame('external', $external['source_type']);
        self::assertSame('computer', $external['platform']);
        self::assertNull($external['mime_type']);
        self::assertNull($external['file_size_bytes']);
        self::assertNotSame($before['download_version'], $external['download_version']);
        $this->get($before['download_url'])->assertRedirect('https://files.example.test/large.zip');

        Storage::disk('learner-attachments')->put('replacement.txt', 'replacement bytes');
        $draft = $this->revisions->draftFor($this->course->fresh());
        $draft->pdfs()->firstOrFail()->forceFill([
            'source_type' => 'upload', 'platform' => 'mobile', 'external_url' => null,
            'file_path' => 'replacement.txt', 'storage_disk' => 'learner-attachments',
            'original_filename' => 'replacement.txt', 'file_extension' => 'txt',
            'mime_type' => 'text/plain', 'file_size' => 17,
        ])->save();
        $this->get($before['download_url'])->assertRedirect('https://files.example.test/large.zip');
        $this->publish($draft);

        $latest = $this->metadata($this->original->id);
        self::assertSame($latest['id'], $this->metadata($external['id'])['id']);
        self::assertSame('upload', $latest['source_type']);
        self::assertSame('mobile', $latest['platform']);
        self::assertNull($latest['external_url']);
        self::assertSame('replacement.txt', $latest['file_name']);
        self::assertNotSame($external['download_version'], $latest['download_version']);
        foreach ([$before['download_url'], $external['download_url']] as $url) {
            $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
            self::assertSame('replacement bytes', $response->streamedContent());
        }
        $this->getJson('/api/v1/courses/'.$this->course->id.'/pdfs')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $latest['id']);
        Http::assertNothingSent();
    }

    #[DataProvider('revocations')]
    public function test_old_published_identity_and_signed_link_cannot_bypass_current_revocation(string $reason): void
    {
        $before = $this->metadata($this->original->id);
        $this->publish($this->revisions->draftFor($this->course));
        if ($reason === 'hidden' || $reason === 'deleted') {
            $draft = $this->revisions->draftFor($this->course->fresh());
            $file = $draft->pdfs()->firstOrFail();
            if ($reason === 'hidden') {
                $file->update(['is_active' => false]);
            } else {
                $file->delete();
            }
            $this->publish($draft);
            $this->getJson('/api/v1/courses/'.$this->course->id.'/pdfs')->assertOk()->assertJsonCount(0, 'data');
        } elseif ($reason === 'unpublished') {
            $this->course->fresh()->forceFill(['is_coming_soon' => true])->save();
        } else {
            CourseEnrollment::query()->update(['is_active' => false]);
        }
        $this->getJson('/api/v1/courses/'.$this->course->id.'/pdfs/'.$this->original->id)
            ->assertStatus(in_array($reason, ['hidden', 'deleted'], true) ? 404 : 403);
        $this->get($before['download_url'])->assertStatus($reason === 'deleted' ? 404 : 403);
        Http::assertNothingSent();
    }

    public static function revocations(): array
    {
        return [['hidden'], ['deleted'], ['unpublished'], ['enrollment revoked']];
    }

    private function metadata(int $id): array
    {
        return $this->getJson('/api/v1/courses/'.$this->course->id.'/pdfs/'.$id)
            ->assertOk()->json('data');
    }

    private function publish(Course $draft): void
    {
        $this->revisions->publish($draft->fresh(), (int) $draft->fresh()->authoring_version, true);
    }
}
