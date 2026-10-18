<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Jobs\RecoverPendingCertificate;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * GET /certificates/{course} only reads (RFC 9110 safe method). A pending
 * artifact is re-queued by the issue POST and by the scheduled
 * certificates:recover-pending command.
 */
final class CertificateReadWritePathTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id, 'course_id' => $this->courseId, 'is_active' => true,
        ]);
    }

    public function test_polling_a_pending_certificate_neither_writes_nor_queues_work(): void
    {
        $publicId = $this->pendingCertificate(now());

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach ([1, 2] as $poll) {
            $this->actingAs($this->user, 'api')
                ->getJson("/api/v1/certificates/{$this->courseId}")
                ->assertStatus(202)
                ->assertJsonPath('code', 'certificate_generating')
                ->assertJsonPath('data.public_id', $publicId)
                ->assertJsonPath('data.status_endpoint', "/api/v1/certificates/{$this->courseId}");
        }

        self::assertSame([], array_values(array_filter(
            $queries,
            fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql) === 1
        )));
        Queue::assertNotPushed(RecoverPendingCertificate::class);
    }

    public function test_the_issue_post_requeues_a_pending_certificate(): void
    {
        $this->pendingCertificate(now());

        $this->actingAs($this->user, 'api')
            ->postJson("/api/v1/certificates/{$this->courseId}/issue", ['holder_name' => 'طالب الاختبار'])
            ->assertStatus(202)
            ->assertJsonPath('code', 'certificate_generating');

        Queue::assertPushed(RecoverPendingCertificate::class, 1);
    }

    public function test_the_scheduled_recovery_requeues_a_stale_pending_certificate(): void
    {
        $this->pendingCertificate(now()->subMinutes(10));

        $this->artisan('certificates:recover-pending', ['--limit' => 10])->assertSuccessful();

        Queue::assertPushed(RecoverPendingCertificate::class, 1);
    }

    private function pendingCertificate(mixed $updatedAt): string
    {
        $publicId = (string) Str::uuid();
        DB::table('certificates')->insert([
            'public_id' => $publicId,
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'holder_name' => 'طالب الاختبار',
            'course_name' => 'دورة تجريبية',
            'certificate_text_template_key' => 'completion',
            'certificate_text' => 'تقديرًا لإتمام متطلبات كورس',
            'image_path' => 'pending',
            'status' => 'active',
            'generated_at' => now(),
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);

        return $publicId;
    }
}
