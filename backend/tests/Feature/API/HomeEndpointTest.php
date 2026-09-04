<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature tests covering settings, public content and app version checking.
 */
class HomeEndpointTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('design_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('youtube_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('tiktok_url')->nullable();
            $table->string('whatsapp_url')->nullable();
            $table->string('telegram_url')->nullable();
            $table->string('technical_contact')->nullable();
            $table->text('policy_content_ar')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_request_id')->unique();
            $table->char('request_fingerprint', 64);
            $table->string('name');
            $table->string('email');
            $table->string('phone');
            $table->text('message');
            $table->boolean('read')->default(false);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('design_settings');

        parent::tearDown();
    }

    public function test_can_get_app_settings(): void
    {
        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.0.about_url', url('/about'))
            ->assertJsonPath('data.0.contact_url', url('/contact'))
            ->assertJsonPath('data.0.privacy_url', url('/privacy-policy'))
            ->assertJsonPath('data.0.terms_url', url('/terms'))
            ->assertJsonPath('data.0.returns_policy_url', url('/returns-policy'))
            ->assertJsonPath('data.0.account_deletion_url', url('/account-deletion'))
            ->assertJsonMissingPath('data.0.about_us_url')
            ->assertJsonMissingPath('data.0.privacy_policy_url')
            ->assertJsonMissingPath('data.0.policy_content');
    }

    public function test_can_get_independent_public_content_pages_as_structured_json(): void
    {
        $this->withHeader('Accept-Language', 'en-US')
            ->getJson('/api/v1/content/pages/privacy')
            ->assertOk()
            ->assertJsonPath('data.slug', 'privacy')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.web_url', route('privacy'))
            ->assertJsonPath('data.content.heading', 'Privacy and Data Protection Policy')
            ->assertJsonStructure(['data' => ['content' => ['sections']]]);
    }

    public function test_contact_page_exposes_the_form_contract_and_accepts_messages_without_phone(): void
    {
        $this->getJson('/api/v1/content/pages/contact')
            ->assertOk()
            ->assertJsonPath('data.slug', 'contact')
            ->assertJsonPath('data.contact.form.method', 'POST')
            ->assertJsonPath('data.contact.form.endpoint', '/api/v1/contact')
            ->assertJsonPath('data.contact.form.required_fields.0', 'name');

        $this->postJson('/api/v1/contact', [
            'name' => 'Test Student',
            'email' => 'student@example.test',
            'message' => 'I need help with my current course.',
        ])->assertCreated()
            ->assertJsonPath('status', 201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('contacts', [
            'name' => 'Test Student',
            'email' => 'student@example.test',
            'phone' => '',
        ]);
    }

    public function test_unknown_public_content_page_returns_not_found(): void
    {
        $this->getJson('/api/v1/content/pages/not-a-page')->assertNotFound();
    }

    public function test_can_check_app_version(): void
    {
        $response = $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => '1.0.0'
        ]);
        $this->assertNotEquals(404, $response->status());
    }
}
