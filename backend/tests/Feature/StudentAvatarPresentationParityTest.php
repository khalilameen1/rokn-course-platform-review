<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\AppFrontNameSpace;
use App\Http\Middleware\RequireAdminMfa;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Actual profile API and dashboard views, not a duplicated URL resolver. */
final class StudentAvatarPresentationParityTest extends TestCase
{
    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        // Match the existing tracked image-upload fixtures: full production
        // migrations in an isolated database, with real commits before bytes.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Queue::fake();
        config(['filesystems.disks.public.url' => 'https://media.example.test/public']);
        // Laravel's fake copies only `throw` from the disk configuration.
        // Pass the CDN URL explicitly so the real resolver sees that contract.
        Storage::fake('public', ['url' => config('filesystems.disks.public.url')]);
        $this->withoutMiddleware([
            AppFrontNameSpace::class,
            WebsiteVisitorCount::class,
            RequireAdminMfa::class,
        ]);
        $this->administrator = User::query()->forceCreate([
            'name' => 'Avatar reviewer',
            'email' => 'avatar-reviewer@example.test',
            'password' => 'test-only',
            'role' => 'admin',
            'active' => true,
        ]);
    }

    public function test_mobile_image_replacement_is_the_image_shown_in_both_student_dashboard_views(): void
    {
        self::assertSame(0, DB::transactionLevel());
        $student = $this->student('profiles/previous.jpg');
        Storage::disk('public')->put('profiles/previous.jpg', 'previous-file');
        $this->legacyPhoto($student);
        $this->assertDashboardAvatar($student, Storage::disk('public')->url('profiles/previous.jpg'));

        $response = $this->actingAs($student, 'api')->postJson('/api/v1/user/profile', [
            'client_request_id' => (string) Str::uuid(),
            'expected_profile_revision' => 0,
            'profile_image' => UploadedFile::fake()->image('replacement.png', 120, 120)->size(2),
        ])->assertOk();

        $student = $student->fresh();
        $canonical = Storage::disk('public')->url((string) $student->profile_image);
        $response->assertJsonPath('data.profile_image', $canonical);
        self::assertNotSame(Storage::disk('public')->url('profiles/previous.jpg'), $canonical);
        Storage::disk('public')->assertExists((string) $student->profile_image);
        // A historical featured relation must not regain ownership of the avatar.
        self::assertSame(1, $student->allPhotos()->where('type', 'featured')->count());
        $this->assertDashboardAvatar($student, $canonical);
        Http::assertNothingSent();
    }

    public function test_https_social_avatar_matches_the_profile_response_and_dashboard_not_a_legacy_photo(): void
    {
        $url = 'https://images.example.test/avatar-current.jpg?revision=2&size=128';
        $student = $this->student($url);
        $this->legacyPhoto($student);
        $this->assertApiAvatar($student, $url);
        $this->assertDashboardAvatar($student, $url);
    }

    public function test_public_disk_keys_and_legacy_storage_prefixes_keep_the_configured_cdn_origin(): void
    {
        foreach (['profiles/current.jpg', '/storage/profiles/current.jpg'] as $path) {
            $student = $this->student($path);
            $this->legacyPhoto($student);
            $canonical = 'https://media.example.test/public/profiles/current.jpg';
            $this->assertApiAvatar($student, $canonical);
            $this->assertDashboardAvatar($student, $canonical);
        }
    }

    public function test_absent_or_insecure_profile_image_uses_the_placeholder_not_an_old_featured_photo(): void
    {
        foreach ([null, 'http://images.example.test/insecure.jpg'] as $path) {
            $student = $this->student($path);
            $this->legacyPhoto($student);
            $this->assertApiAvatar($student, null);
            $this->assertDashboardAvatar($student, '/images/avatar/customer_blank.png');
        }
    }

    private function assertApiAvatar(User $student, ?string $expected): void
    {
        $this->actingAs($student, 'api')
            ->getJson('/api/v1/user/profile?include_learning=0')
            ->assertOk()
            ->assertJsonPath('data.profile_image', $expected);
    }

    private function assertDashboardAvatar(User $student, string $expected): void
    {
        $this->actingAs($this->administrator, 'web');
        foreach ([
            'user-avatar' => route('admin.users.index', ['search' => 'UID: '.$student->id]),
            'profile-avatar' => route('admin.users.show', $student->id),
        ] as $class => $url) {
            $response = $this->get($url)->assertOk();
            preg_match_all(
                '#<img\\b(?=[^>]*\\bclass="[^"<>]*\\b'.$class.'\\b[^"<>]*")[^>]*>#',
                $response->getContent(),
                $images
            );
            self::assertCount(1, $images[0], $url);
            self::assertStringContainsString('src="'.e($expected).'"', $images[0][0]);
            $response->assertDontSee('legacy-featured-avatar.jpg', false);
            $response->assertDontSee('http://images.example.test/insecure.jpg', false);
        }
    }

    private function student(?string $image): User
    {
        return User::query()->forceCreate([
            'name' => 'Student avatar',
            'email' => Str::uuid().'@example.test',
            'password' => 'not-a-login-password',
            'role' => 'client',
            'active' => true,
            'profile_image' => $image,
            'profile_revision' => 0,
        ]);
    }

    private function legacyPhoto(User $student): void
    {
        $student->allPhotos()->create([
            'path' => 'users/legacy-featured-avatar.jpg',
            'type' => 'featured',
        ]);
    }
}
