<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverOutboxEvent;
use App\Models\OutboxEvent;
use App\Models\ProductEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductEventEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('product_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->char('actor_key', 64)->nullable();
            $table->char('session_key', 64)->nullable();
            $table->string('event_name', 64);
            $table->string('source', 32);
            $table->string('screen_key', 64)->nullable();
            $table->string('campaign_key', 64)->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('module_id')->nullable();
            $table->unsignedBigInteger('lesson_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedSmallInteger('milestone')->nullable();
            $table->integer('value')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
        });
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_key')->unique();
            $table->string('topic', 96);
            $table->string('aggregate_type', 64)->nullable();
            $table->string('aggregate_id', 96)->nullable();
            $table->json('payload');
            $table->string('status', 24);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->char('last_error_fingerprint', 64)->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('product_events');
        parent::tearDown();
    }

    public function test_mobile_contract_is_privacy_safe_idempotent_and_outboxed(): void
    {
        Queue::fake();
        $eventId = (string) Str::uuid();
        $session = (string) Str::uuid();
        $payload = [
            'event_id' => $eventId,
            'session_key' => $session,
            'event_name' => 'course_opened',
            'source' => 'app',
            'screen_key' => 'home',
            'course_id' => 42,
            'occurred_at' => now()->toIso8601String(),
        ];

        $this->postJson('/api/v1/product-events', $payload)
            ->assertStatus(202)
            ->assertJson(['accepted' => true, 'accepted_count' => 1, 'duplicate_count' => 0]);

        $event = ProductEvent::query()->sole();
        self::assertSame(64, strlen((string) $event->actor_key));
        self::assertSame(64, strlen((string) $event->session_key));
        self::assertNotSame($session, $event->session_key);
        self::assertSame('product.course_opened', OutboxEvent::query()->sole()->topic);
        Queue::assertPushed(DeliverOutboxEvent::class, 1);

        $this->postJson('/api/v1/product-events', $payload)
            ->assertStatus(202)
            ->assertJson(['accepted_count' => 0, 'duplicate_count' => 1]);
        self::assertSame(1, ProductEvent::query()->count());
        self::assertSame(1, OutboxEvent::query()->count());

        $payload['event_name'] = 'home_viewed';
        $this->postJson('/api/v1/product-events', $payload)->assertStatus(409);
    }

    public function test_public_client_cannot_impersonate_a_committed_server_purchase(): void
    {
        $this->postJson('/api/v1/product-events', [
            'event_id' => (string) Str::uuid(), 'session_key' => (string) Str::uuid(),
            'event_name' => 'purchase_completed', 'source' => 'server',
            'screen_key' => 'course_details', 'course_id' => 42,
            'occurred_at' => now()->toIso8601String(),
        ])->assertUnprocessable();
        self::assertSame(0, ProductEvent::query()->count());
    }

    public function test_batch_contract_rejects_unbounded_or_free_form_data(): void
    {
        Queue::fake();
        $base = [
            'session_key' => (string) Str::uuid(),
            'source' => 'app',
            'occurred_at' => now()->toIso8601String(),
        ];
        $events = [
            $base + ['event_id' => (string) Str::uuid(), 'event_name' => 'home_viewed', 'screen_key' => 'home'],
            $base + [
                'event_id' => (string) Str::uuid(),
                'event_name' => 'lesson_milestone',
                'screen_key' => 'player',
                'lesson_id' => 7,
                'milestone' => 50,
            ],
        ];

        $this->postJson('/api/v1/product-events', ['events' => $events])
            ->assertStatus(202)
            ->assertJson(['accepted_count' => 2]);

        $events[0]['message'] = 'raw text is forbidden';
        $this->postJson('/api/v1/product-events', ['events' => $events])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['events.0.payload']);

        $events[0] = array_merge($base, [
            'event_id' => (string) Str::uuid(),
            'event_name' => 'home_viewed',
            'source' => 'system',
        ]);
        $this->postJson('/api/v1/product-events', ['events' => $events])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['events.0.source']);
    }
}
