<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\FeedbackReport;
use App\Models\SupportCaseEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminSupportCaseReplayTest extends TestCase
{
    use RefreshDatabase;

    private FeedbackReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $admin = User::query()->forceCreate([
            'name' => 'Support Admin',
            'email' => 'support-admin@rokn.test',
            'password' => Hash::make('not-used-in-this-test'),
            'role' => 'admin',
            'active' => true,
        ]);
        $this->actingAs($admin, 'web');
        $this->report = FeedbackReport::query()->create([
            'public_id' => (string) Str::ulid(),
            'category' => 'bug',
            'status' => 'new',
            'priority' => 'normal',
            'message' => 'يتوقف التطبيق عند فتح صفحة الحساب',
            'version' => 1,
        ]);
    }

    public function test_lost_response_replay_of_the_same_state_is_a_no_op(): void
    {
        $payload = [
            'version' => 1,
            'status' => 'reviewing',
            'priority' => 'high',
            'assigned_to' => null,
            'resolution_kind' => null,
        ];

        $this->patch(route('admin.feedback.update', $this->report), $payload)
            ->assertRedirect();
        $this->patch(route('admin.feedback.update', $this->report), $payload)
            ->assertRedirect();

        $this->report->refresh();
        self::assertSame(2, (int) $this->report->version);
        self::assertSame('reviewing', $this->report->status);
        self::assertSame('high', $this->report->priority);
        self::assertSame(1, SupportCaseEvent::query()
            ->where('feedback_report_id', $this->report->id)
            ->where('event_type', 'updated')
            ->count());
    }

    public function test_stale_form_with_a_different_state_is_still_rejected(): void
    {
        $this->report->update(['status' => 'reviewing', 'version' => 2]);

        $this->patch(route('admin.feedback.update', $this->report), [
            'version' => 1,
            'status' => 'resolved',
            'priority' => 'normal',
            'assigned_to' => null,
            'resolution_kind' => 'fixed',
        ])->assertStatus(409);

        self::assertSame('reviewing', $this->report->fresh()->status);
    }
}
