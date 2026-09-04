<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Course;
use App\Services\CertificateTextTemplateService;
use Tests\TestCase;

final class CertificateTextTemplateServiceTest extends TestCase
{
    public function test_authoring_catalogue_and_issuance_resolve_the_same_clean_template(): void
    {
        config()->set('certificate.text_templates', [
            'projects' => [
                'label' => '  المشروعات  ',
                'description' => '  للكورسات التطبيقية  ',
                'text' => '  تقديرًا لإنجاز مشروعات كورس  ',
            ],
            'empty' => ['label' => 'فارغ', 'description' => '', 'text' => '   '],
        ]);

        $service = app(CertificateTextTemplateService::class);
        $course = new Course();
        $course->forceFill(['certificate_text_template_key' => 'projects']);

        self::assertSame(['projects'], $service->keys());
        self::assertSame(
            'تقديرًا لإنجاز مشروعات كورس',
            $service->catalogue()['projects']['text']
        );
        self::assertSame(
            ['key' => 'projects', 'text' => 'تقديرًا لإنجاز مشروعات كورس'],
            $service->forCourse($course)
        );
        self::assertNull($service->resolve('empty'));
    }
}
