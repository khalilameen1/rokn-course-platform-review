<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\AppFrontNameSpace;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Http\Requests\Admin\CourseRequest;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use App\Services\PublicPortfolioService;
use App\Services\CertificateQrDestinationService;
use App\Services\CertificateService;
use App\Support\RoknAppLink;
use App\Support\RoknPublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicCertificateIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([AppFrontNameSpace::class, WebsiteVisitorCount::class]);
        Storage::fake('public');
        config()->set('certificate.disk', 'public');
    }

    public function test_credential_url_and_issued_identity_survive_profile_and_course_edits(): void
    {
        [$user, $course, $certificate] = $this->credential();
        $permanentUrl = RoknPublicUrl::certificate((string) $certificate->public_id);

        self::assertSame($permanentUrl, RoknPublicUrl::certificate((string) $certificate->public_id));

        $certificate->forceFill([
            'public_id' => (string) Str::uuid(),
            'holder_name' => 'اسم مستبدل',
            'course_name' => 'كورس مستبدل',
            'certificate_text_template_key' => 'projects',
            'certificate_text' => 'نص مستبدل',
            'generated_at' => now()->addDay(),
            'verification_level' => 'reviewed_project',
        ])->save();
        $preservedCertificate = $certificate->fresh();
        self::assertSame(
            $permanentUrl,
            RoknPublicUrl::certificate((string) $preservedCertificate->public_id)
        );
        self::assertSame('completion', $preservedCertificate->verification_level);

        $user->forceFill([
            'name' => 'اسم حالي مختلف',
            'portfolio_slug' => 'new-profile-slug',
        ])->save();
        $course->forceFill([
            'name_ar' => 'اسم الكورس الحالي',
            'certificate_text_template_key' => 'projects',
        ])->save();

        $this->get('/c/'.$certificate->public_id)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('اسم حامل الشهادة')
            ->assertSee('اسم الكورس وقت الإصدار')
            ->assertSee('تقديرًا لإتمام المتطلبات التطبيقية لكورس')
            ->assertSee($certificate->generated_at->locale('ar')->translatedFormat('j F Y'))
            ->assertSee((string) $certificate->public_id)
            ->assertSee('شهادة سارية')
            ->assertDontSee('ملف الطالب على ركن')
            ->assertDontSee('متعلم في ركن');

        $payload = (new CertificateResource($certificate->fresh()))->resolve();
        self::assertSame('اسم حامل الشهادة', $payload['holder_name']);
        self::assertSame('اسم الكورس وقت الإصدار', $payload['course_name']);
        self::assertSame((int) $course->id, $payload['course_id']);
        self::assertArrayNotHasKey('course', $payload);
        self::assertArrayNotHasKey('certificate_id', $payload);
        self::assertArrayNotHasKey('download_url', $payload);
        self::assertArrayNotHasKey('portfolio_url', $payload);
        self::assertArrayNotHasKey('share_url', $payload);
        self::assertSame('portfolio', $payload['qr_destination']['type']);
        self::assertSame(
            RoknPublicUrl::portfolio((string) $user->fresh()->portfolio_slug),
            $payload['qr_destination']['url']
        );
        self::assertSame('applied', $payload['certificate_text_template_key']);
        self::assertSame(
            'تقديرًا لإتمام المتطلبات التطبيقية لكورس',
            $payload['certificate_text']
        );
        self::assertSame($permanentUrl, $payload['verification_url']);
        self::assertSame(
            RoknPublicUrl::certificatePdf((string) $certificate->public_id),
            $payload['certificate_pdf_url']
        );
    }

    public function test_active_certificate_downloads_the_issued_artwork_as_pdf(): void
    {
        [, , $certificate] = $this->credential();

        $response = $this->get('/c/'.$certificate->public_id.'/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('max-age=0', $cacheControl);
        self::assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_revoked_certificate_artifact_and_pdf_are_not_downloadable(): void
    {
        [, , $certificate] = $this->credential();
        $certificate->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
        ])->save();

        $this->get('/c/'.$certificate->public_id.'/artifact')->assertNotFound();
        $this->get('/c/'.$certificate->public_id.'/download')->assertNotFound();
    }

    public function test_certificate_tab_is_a_supported_internal_notification_destination(): void
    {
        self::assertSame(
            'rokn://profile/certificates',
            RoknAppLink::normalize('rokn://profile/certificates')
        );
        self::assertNull(RoknAppLink::normalize('rokn://profile/anything'));
    }

    public function test_revoked_credential_is_verification_only_and_uses_snapshots(): void
    {
        [$user, $course, $certificate] = $this->credential();
        $user->forceFill([
            'name' => 'اسم خاص حالي',
            'bio' => 'سيرة خاصة لا ينبغي كشفها',
        ])->save();
        $course->forceFill(['name_ar' => 'اسم كورس جديد'])->save();
        $certificate->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
        ])->save();

        $this->get(route('certificate.public', ['publicId' => $certificate->public_id]))
            ->assertOk()
            ->assertSee('اسم حامل الشهادة')
            ->assertSee('اسم الكورس وقت الإصدار')
            ->assertSee('ملغاة')
            ->assertDontSee('اسم خاص حالي')
            ->assertDontSee('سيرة خاصة لا ينبغي كشفها')
            ->assertDontSee('اسم كورس جديد');
    }

    public function test_incomplete_snapshot_is_never_published_as_a_credential(): void
    {
        [, , $certificate] = $this->credential();
        \Illuminate\Support\Facades\DB::table('certificates')
            ->where('id', $certificate->id)
            ->update(['certificate_text' => null]);

        $certificate = $certificate->fresh();
        self::assertFalse($certificate->hasCompleteCredentialSnapshot());
        $this->get('/c/'.$certificate->public_id)->assertNotFound();
        $this->get('/c/'.$certificate->public_id.'/artifact')->assertNotFound();
        $this->get('/c/'.$certificate->public_id.'/download')->assertNotFound();
    }

    public function test_course_certificate_wording_accepts_only_a_complete_approved_choice(): void
    {
        $base = [
            'name_ar' => 'كورس اختبار',
            'authoring_request_id' => (string) Str::uuid(),
        ];
        $request = CourseRequest::create('/', 'POST', $base);
        $rules = $request->rules();

        self::assertFalse(Validator::make(
            $base + ['certificate_text_template_key' => 'projects'],
            $rules
        )->fails());
        self::assertFalse(Validator::make(
            $base + ['certificate_text_template_key' => 'skills'],
            $rules
        )->fails());
        self::assertSame(
            'تقديرًا لإتمام التدريب العملي في كورس',
            config('certificate.text_templates.skills.text')
        );
        self::assertTrue(Validator::make(
            $base + ['certificate_text_template_key' => ''],
            $rules
        )->errors()->has('certificate_text_template_key'));
        self::assertTrue(Validator::make(
            $base + ['certificate_text_template_key' => 'custom-live-text'],
            $rules
        )->errors()->has('certificate_text_template_key'));
        self::assertTrue(Validator::make(
            $base,
            $rules
        )->errors()->has('certificate_text_template_key'));
    }

    public function test_live_certificate_template_matches_the_renderer_canvas_contract(): void
    {
        $path = (string) config('certificate.template_path');
        self::assertFileExists($path);

        $size = getimagesize($path);
        self::assertIsArray($size);
        self::assertSame(1200, $size[0]);
        self::assertSame(900, $size[1]);

        foreach ((array) config('certificate.text_positions') as $position) {
            self::assertGreaterThanOrEqual(0, $position['x']);
            self::assertLessThanOrEqual(1, $position['x']);
            self::assertGreaterThanOrEqual(0, $position['y']);
            self::assertLessThanOrEqual(1, $position['y']);
        }

        $source = file_get_contents(resource_path('certificates/certificate_template_v2.svg'));
        self::assertIsString($source);
        self::assertStringNotContainsString('امسح الرمز لعرض بياناتها', $source);
        self::assertStringNotContainsString('>التحقق من الشهادة<', $source);
    }

    public function test_qr_destination_follows_the_immutable_certificate_template_family(): void
    {
        [$user, , $practical] = $this->credential();
        $destinations = app(CertificateQrDestinationService::class);

        $practicalDestination = $destinations->for($practical);
        self::assertNotNull($practicalDestination);
        self::assertSame('portfolio', $practicalDestination['type']);
        self::assertSame('شاهد الأعمال', $practicalDestination['title']);
        self::assertMatchesRegularExpression(
            '~^https://rokn\.app/@rokn-[a-z0-9]{24}$~',
            $practicalDestination['url']
        );
        self::assertSame(
            RoknPublicUrl::portfolio((string) $user->fresh()->portfolio_slug),
            $practicalDestination['url']
        );
        foreach (['applied', 'skills', 'projects'] as $templateKey) {
            $practicalVariant = new Certificate();
            $practicalVariant->forceFill([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'certificate_text_template_key' => $templateKey,
            ]);
            $practicalVariant->setRelation('user', $user->fresh());
            $resourceDestination = (new CertificateResource($practicalVariant))
                ->resolve()['qr_destination'];

            self::assertSame('portfolio', $resourceDestination['type']);
            self::assertSame(
                RoknPublicUrl::portfolio((string) $user->fresh()->portfolio_slug),
                $resourceDestination['url']
            );
        }

        $theoretical = new Certificate();
        $theoretical->forceFill([
            'public_id' => (string) Str::uuid(),
            'certificate_text_template_key' => 'knowledge',
        ]);
        $theoreticalDestination = $destinations->for($theoretical);
        self::assertNotNull($theoreticalDestination);
        self::assertSame('certificate', $theoreticalDestination['type']);
        self::assertSame('تحقق من الشهادة', $theoreticalDestination['title']);
        self::assertSame(
            RoknPublicUrl::certificate((string) $theoretical->public_id),
            $theoreticalDestination['url']
        );
        self::assertSame(
            $theoreticalDestination,
            (new CertificateResource($theoretical))->resolve()['qr_destination']
        );
    }

    public function test_public_verification_remains_available_when_only_the_artwork_is_missing(): void
    {
        [, , $certificate] = $this->credential();
        Storage::disk('public')->delete((string) $certificate->image_path);

        $this->get('/c/'.$certificate->public_id)
            ->assertOk()
            ->assertSee('شهادة سارية')
            ->assertSee('اسم حامل الشهادة')
            ->assertSee('اسم الكورس وقت الإصدار')
            ->assertDontSee('عرض الشهادة')
            ->assertDontSee('تحميل PDF');
    }

    public function test_long_arabic_certificate_fields_stay_inside_the_editorial_main_field(): void
    {
        $reflection = new \ReflectionClass(CertificateService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $shape = $reflection->getMethod('shapeIfArabic');
        $fit = $reflection->getMethod('fittedFontSize');
        $place = $reflection->getMethod('horizontalTextPlacement');
        $fontPath = (string) config('certificate.font_regular');
        $positions = (array) config('certificate.text_positions');
        $fields = [
            'name' => 'عبد الرحمن مصطفى عبد العزيز الشافعي',
            'achievement' => 'تقديرًا لإتمام المتطلبات التطبيقية لكورس',
            'course' => 'صناعة المحتوى الاحترافي واستراتيجيات النمو بالفيديو القصير',
        ];

        foreach ($fields as $key => $value) {
            $text = $shape->invoke($service, $value);
            self::assertStringNotContainsString("\n", $text);
            $position = $positions[$key];
            $position['size'] = $fit->invoke(
                $service,
                $text,
                $fontPath,
                $position,
                1200
            );
            $placement = $place->invoke(
                $service,
                $text,
                $fontPath,
                $position,
                1200
            );

            self::assertGreaterThanOrEqual(340, $placement['left'], $key);
            self::assertLessThanOrEqual(1080, $placement['right'], $key);
        }
    }

    public function test_pending_artwork_does_not_hide_a_valid_credential_or_expose_an_artifact(): void
    {
        [, , $certificate] = $this->credential();
        $certificate->forceFill(['image_path' => 'pending'])->save();

        $this->get('/c/'.$certificate->public_id)
            ->assertOk()
            ->assertSee('شهادة سارية')
            ->assertDontSee('عرض الشهادة');
        $this->get('/c/'.$certificate->public_id.'/artifact')->assertNotFound();
        $this->get('/c/'.$certificate->public_id.'/download')->assertNotFound();
        $this->get('/c/'.Str::uuid())->assertNotFound();
        $this->get('/c/123')->assertNotFound();
    }

    public function test_numeric_legacy_profile_alias_cannot_publish_an_unshared_portfolio(): void
    {
        [$user, , $certificate] = $this->credential();
        $user->forceFill(['portfolio_slug' => null])->save();
        $service = app(PublicPortfolioService::class);

        self::assertNull($service->find('student-'.$user->id));

        $this->get('/c/'.$certificate->public_id)
            ->assertOk()
            ->assertSee('اسم حامل الشهادة')
            ->assertDontSee('ملف الطالب على ركن');
    }

    public function test_retiring_a_course_keeps_issued_certificate_history_readable(): void
    {
        [, $course, $certificate] = $this->credential();

        $course->delete();

        self::assertNull(Course::query()->find($course->id));
        self::assertNotNull(Course::withTrashed()->find($course->id));
        self::assertSame(
            'اسم الكورس وقت الإصدار',
            $certificate->fresh()->course_name
        );
        $this->get('/c/'.$certificate->public_id)
            ->assertOk()
            ->assertSee('اسم الكورس وقت الإصدار');
    }

    /** @return array{User, Course, Certificate} */
    private function credential(): array
    {
        $user = new User();
        $user->forceFill([
            'name' => 'اسم حامل الشهادة',
            'email' => 'credential-'.Str::uuid().'@example.test',
            'role' => 'client',
            'active' => true,
            'portfolio_slug' => 'old-profile',
        ])->save();

        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'اسم الكورس وقت الإصدار',
            'name_en' => 'Course at issuance',
        ])->save();

        $certificate = Certificate::create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'course_id' => $course->id,
            'holder_name' => 'اسم حامل الشهادة',
            'course_name' => 'اسم الكورس وقت الإصدار',
            'certificate_text_template_key' => 'applied',
            'certificate_text' => 'تقديرًا لإتمام المتطلبات التطبيقية لكورس',
            'image_path' => 'certificates/issued.png',
            'generated_at' => now(),
            'status' => 'active',
            'verification_level' => 'completion',
        ]);
        Storage::disk('public')->put(
            'certificates/issued.png',
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAQAAAADCAIAAAA7l3MiAAAAFElEQVR4nGP8z8DAwMDAxAADCBYAG8cBBRuqgFoAAAAASUVORK5CYII=',
                true
            )
        );

        return [$user, $course, $certificate];
    }
}
