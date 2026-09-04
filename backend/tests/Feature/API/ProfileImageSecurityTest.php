<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

final class ProfileImageSecurityTest extends ApiTestCase
{
    public function test_svg_profile_image_is_rejected_by_content_and_extension(): void
    {
        Storage::fake('public');
        $svg = UploadedFile::fake()->createWithContent(
            'avatar.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/user/profile', ['profile_image' => $svg])
            ->assertUnprocessable();

        self::assertNull($this->user->fresh()->profile_image);
    }

    public function test_valid_raster_is_reencoded_to_a_server_named_jpeg(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user, 'api')->postJson('/api/v1/user/profile', [
            'profile_image' => UploadedFile::fake()->image('avatar.png', 120, 120)->size(2),
        ])->assertOk();

        $path = (string) $this->user->fresh()->profile_image;
        self::assertMatchesRegularExpression('#^profiles/[0-9a-f-]+\.jpg$#', $path);
        Storage::disk('public')->assertExists($path);
        self::assertSame("\xFF\xD8", substr((string) Storage::disk('public')->get($path), 0, 2));
    }

    public function test_legacy_svg_quarantine_keeps_a_private_copy_and_clears_public_reference(): void
    {
        Storage::fake('public');
        Storage::fake('security-quarantine');
        Storage::disk('public')->put('profiles/legacy.svg', '<svg><script>alert(1)</script></svg>');
        $this->user->update(['profile_image' => 'profiles/legacy.svg']);

        self::assertSame(0, Artisan::call('security:quarantine-profile-svg', ['--execute' => true]));

        self::assertNull($this->user->fresh()->profile_image);
        Storage::disk('public')->assertMissing('profiles/legacy.svg');
        Storage::disk('security-quarantine')->assertExists('profile-svg/' . $this->user->id . '-legacy.svg');
    }
}
