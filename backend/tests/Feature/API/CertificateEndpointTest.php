<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Feature tests covering Certificate API endpoints:
 * listing user earned certificates and retrieving specific course certificates.
 */
class CertificateEndpointTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config()->set('certificate.disk', 'public');
        Storage::disk('public')->put('certificates/test-certificate.png', 'certificate');
    }

    public function test_can_list_certificates(): void
    {
        $this->grantCourseAccess();
        $publicId = (string) Str::uuid();
        DB::table('certificates')->insert([
            'public_id' => $publicId,
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'holder_name' => 'طالب الاختبار',
            'course_name' => 'دورة تجريبية',
            'certificate_text_template_key' => 'completion',
            'certificate_text' => 'تقديرًا لإتمام متطلبات كورس',
            'image_path' => 'certificates/test-certificate.png',
            'status' => 'active',
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherUser = User::query()->create([
            'name' => 'Other Student',
            'email' => 'certificate-owner@example.test',
            'phone' => '01000000009',
            'password' => bcrypt('password'),
            'active' => true,
        ]);
        DB::table('certificates')->insert([
            'public_id' => (string) Str::uuid(),
            'user_id' => $otherUser->id,
            'course_id' => $this->courseId,
            'holder_name' => 'طالب آخر',
            'course_name' => 'دورة تجريبية',
            'certificate_text_template_key' => 'completion',
            'certificate_text' => 'تقديرًا لإتمام متطلبات كورس',
            'image_path' => 'certificates/private.png',
            'status' => 'active',
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/certificates')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $publicId)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.course_id', $this->courseId)
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonMissingPath('data.0.certificate_id')
            ->assertJsonMissingPath('data.0.course')
            ->assertJsonMissingPath('data.0.download_url')
            ->assertJsonMissingPath('data.0.portfolio_url')
            ->assertJsonMissingPath('data.0.share_url')
            ->assertJsonStructure([
                'data' => [[
                    'public_id', 'course_id', 'certificate_url',
                    'certificate_pdf_url', 'verification_url',
                    'status', 'verification_level',
                    'generated_at',
                ]],
            ]);
    }

    public function test_can_view_course_certificate(): void
    {
        $this->grantCourseAccess();
        $publicId = (string) Str::uuid();
        DB::table('certificates')->insert([
            'public_id' => $publicId,
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'holder_name' => 'طالب الاختبار',
            'course_name' => 'دورة تجريبية',
            'certificate_text_template_key' => 'completion',
            'certificate_text' => 'تقديرًا لإتمام متطلبات كورس',
            'image_path' => 'certificates/test-certificate.png',
            'status' => 'active',
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/certificates/{$this->courseId}")
            ->assertOk()
            ->assertJsonPath('data.public_id', $publicId)
            ->assertJsonPath('data.course_id', $this->courseId);
    }

    public function test_revoked_certificate_remains_in_owner_list_to_prevent_reissue(): void
    {
        $this->grantCourseAccess();
        $publicId = (string) Str::uuid();
        DB::table('certificates')->insert([
            'public_id' => $publicId,
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'holder_name' => 'طالب الاختبار',
            'course_name' => 'دورة تجريبية',
            'certificate_text_template_key' => 'completion',
            'certificate_text' => 'تقديرًا لإتمام متطلبات كورس',
            'image_path' => 'certificates/test-certificate.png',
            // Either marker is terminal. This deliberately models a process
            // interrupted between recording the timestamp and status update.
            'status' => 'active',
            'revoked_at' => now(),
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $publicId)
            ->assertJsonPath('data.0.status', 'revoked')
            ->assertJsonPath('data.0.certificate_url', '');

        $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/certificates/{$this->courseId}")
            ->assertGone()
            ->assertJsonPath('code', 'certificate_revoked');
    }

    public function test_certificate_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/certificates')->assertUnauthorized();
        $this->getJson("/api/v1/certificates/{$this->courseId}")->assertUnauthorized();
    }

    private function grantCourseAccess(): void
    {
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
