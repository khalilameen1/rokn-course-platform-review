<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\FeedbackReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FeedbackCasePaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->freezeTime();
    }

    public function test_learner_can_page_through_only_own_cases_and_open_the_twenty_first_case(): void
    {
        $learner = $this->learner('support-pages@rokn.test');
        $other = $this->learner('other-support-pages@rokn.test');
        $token = $learner->generateApiToken();
        $oldCase = $this->report($learner, 'البلاغ القديم المطلوب متابعته');
        $oldCase->forceFill(['updated_at' => now()->subDay()])->save();

        $recentIds = [];
        for ($index = 0; $index < 20; $index++) {
            $recentIds[] = $this->report($learner, 'بلاغ أحدث '.$index)->public_id;
        }
        // Other owners' newer cases must not consume this learner's page slots.
        $foreignCase = $this->report($other, 'بلاغ الحساب الآخر');
        $guestCase = $this->report(null, 'بلاغ ضيف');

        $first = $this->withToken($token)->getJson('/api/v1/feedback?page=1')
            ->assertOk()
            ->assertJsonCount(20, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.pagination.has_more', true);
        self::assertSame(array_reverse($recentIds), array_column($first->json('data.items'), 'public_id'));

        $second = $this->withToken($token)->getJson('/api/v1/feedback?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.public_id', $oldCase->public_id)
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.pagination.has_more', false);
        self::assertSame(
            [...array_reverse($recentIds), $oldCase->public_id],
            array_column([...$first->json('data.items'), ...$second->json('data.items')], 'public_id')
        );

        $this->withToken($token)->getJson('/api/v1/feedback/'.$oldCase->public_id)
            ->assertOk()
            ->assertJsonPath('data.public_id', $oldCase->public_id)
            ->assertJsonPath('data.message', $oldCase->message)
            ->assertJsonPath('data.status', 'received');
        $this->withToken($token)->getJson('/api/v1/feedback/'.$foreignCase->public_id)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/feedback/'.$guestCase->public_id)->assertNotFound();
    }

    public function test_empty_requested_page_is_terminal(): void
    {
        $learner = $this->learner('support-empty-page@rokn.test');
        $this->report($learner, 'حالة واحدة');
        // This is also Laravel's response after earlier pages shrink while a
        // client is following pagination, not a malformed pagination contract.
        $this->withToken($learner->generateApiToken())->getJson('/api/v1/feedback?page=2')
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.last_page', 1)
            ->assertJsonPath('data.pagination.has_more', false);
    }

    public function test_guest_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/feedback')->assertUnauthorized();
    }

    private function learner(string $email): User
    {
        return User::query()->forceCreate([
            'name' => 'Support Learner',
            'email' => $email,
            'role' => 'client',
            'active' => true,
        ]);
    }

    private function report(?User $owner, string $message): FeedbackReport
    {
        return FeedbackReport::query()->create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $owner?->id,
            'category' => 'bug',
            'status' => 'new',
            'priority' => 'normal',
            'message' => $message,
            'version' => 1,
        ]);
    }
}
