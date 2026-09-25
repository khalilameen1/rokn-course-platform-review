<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\User;
use App\Services\BunnyDeliveryService;
use App\Support\RoknPublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PortfolioSharingAwarenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspended_owner_sees_status_and_keeps_private_access_to_work(): void
    {
        $owner = $this->owner();
        $item = $this->item($owner);
        $this->approveFixture($owner);
        $owner->forceFill(['portfolio_sharing_suspended_at' => now()])->save();
        $this->mock(BunnyDeliveryService::class)->shouldReceive('storageUrl')
            ->andReturn('https://cdn.rokn.test/private-owner-image.jpg');

        $this->actingAs($owner, 'api')->getJson('/api/v1/portfolio-profile')
            ->assertOk()->assertJsonPath('data.sharing_suspended', true)
            ->assertJsonPath('data.public_url', null);
        $this->getJson('/api/v1/user/profile')->assertOk()
            ->assertJsonPath('data.portfolio_url', null)
            ->assertJsonPath('data.portfolio_slug', $owner->portfolio_slug);
        self::assertNull((new \App\Http\Resources\UsersResource($owner))->resolve()['profile_deeplink']);
        $this->getJson('/api/v1/portfolio')->assertOk()
            ->assertJsonPath('data.0.id', $item->id);
        $this->get(route('portfolio.public', $owner->portfolio_slug))->assertNotFound();
        $this->getJson('/api/v1/public/portfolios/'.$owner->portfolio_slug)->assertNotFound();
        $this->get(route('portfolio.media', [$owner->portfolio_slug, $item->mediaFiles->first()->public_id]))->assertNotFound();

        $owner->forceFill(['portfolio_sharing_suspended_at' => null])->save();
        $this->actingAs($owner->fresh(), 'api')->getJson('/api/v1/portfolio-profile')
            ->assertOk()->assertJsonPath('data.sharing_suspended', false)
            ->assertJsonPath('data.public_url', RoknPublicUrl::portfolio($owner->portfolio_slug));
        $this->getJson('/api/v1/user/profile')->assertOk()
            ->assertJsonPath('data.portfolio_url', RoknPublicUrl::portfolio($owner->portfolio_slug));
        self::assertSame(RoknPublicUrl::portfolio($owner->portfolio_slug),
            (new \App\Http\Resources\UsersResource($owner->fresh()))->resolve()['profile_deeplink']);
    }

    public function test_only_admin_can_preview_suspended_public_work_and_its_media(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $owner = $this->owner();
        $published = $this->item($owner);
        $private = $this->item($owner, false);
        $owner->forceFill(['portfolio_sharing_suspended_at' => now()])->save();
        $preview = route('admin.portfolio-preview.show', $owner);
        $media = app(\App\Services\PublicPortfolioService::class)->adminPreview($owner)['projects'][0]['media'][0]['image_url'];
        $this->get($preview)->assertRedirect();
        $this->get($media)->assertRedirect();
        $this->actingAs($this->owner('moderator'), 'web')->get($preview)->assertForbidden();
        $this->get($media)->assertForbidden();
        $this->flushSession();
        $this->actingAs($owner, 'web')->get($preview)->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->owner('admin'), 'web')->get($preview)->assertOk()
            ->assertSee('معاينة إدارية خاصة')->assertSee($published->title)
            ->assertDontSee($private->title)->assertDontSee('الإبلاغ عن محتوى')
            ->assertSee(e($media), false)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->mock(BunnyDeliveryService::class)->shouldReceive('storageUrl')
            ->once()->andReturn('https://cdn.rokn.test/admin-image.jpg');
        $this->get($media)->assertRedirect('https://cdn.rokn.test/admin-image.jpg');
        $this->get(route('admin.portfolio-preview.media', [$owner, $private->mediaFiles->first()->public_id]))->assertNotFound();
        $this->get(route('portfolio.media', [$owner->portfolio_slug, $published->mediaFiles->first()->public_id]))->assertNotFound();
        self::assertNotNull($owner->fresh()->portfolio_sharing_suspended_at);
    }

    private function owner(string $role = 'client'): User
    {
        return User::query()->forceCreate([
            'name' => 'Portfolio Owner', 'email' => Str::uuid().'@rokn.test',
            'role' => $role, 'active' => true,
            'portfolio_slug' => 'rokn-'.strtolower(Str::random(24)),
        ]);
    }

    private function approveFixture(User $owner): void
    {
        $owner = $owner->fresh();
        $snapshot = app(\App\Services\PortfolioReviewReadService::class)->snapshot($owner);
        $owner->forceFill(['portfolio_sharing_status' => 'approved', 'portfolio_approved_hash' => $snapshot['hash']])->save();
    }

    private function item(User $owner, bool $public = true): PortfolioItem
    {
        $item = PortfolioItem::query()->create([
            'user_id' => $owner->id, 'title' => ($public ? 'Published-' : 'Private-').Str::uuid(),
            'is_public' => $public,
        ]);
        PortfolioMedia::query()->create([
            'portfolio_item_id' => $item->id, 'file_path' => 'portfolio/example.jpg', 'file_type' => 'image',
        ]);

        return $item->fresh(['mediaFiles']);
    }
}
