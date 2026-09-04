<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProjectSubmissionCanonicalStateContractTest extends TestCase
{
    public function test_learner_runtime_has_no_shadow_evaluation_reads_or_writes(): void
    {
        $runtimeFiles = [
            'app/Services/CourseRevisionLearnerReadService.php',
            'app/Services/ProjectSubmissionService.php',
            'app/Services/CertificateEligibilityService.php',
            'app/Services/CourseModuleAccessService.php',
            'app/Http/Controllers/API/ProjectController.php',
            'app/Http/Controllers/API/PortfolioController.php',
            'app/Http/Resources/CourseResource.php',
            'app/Models/Project.php',
            'app/Models/CourseModule.php',
        ];

        foreach ($runtimeFiles as $path) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
            self::assertIsString($source, $path);
            self::assertStringNotContainsString('use App\Models\UserProjectEvaluation;', $source, $path);
            self::assertStringNotContainsString('UserProjectEvaluation::', $source, $path);
            self::assertStringNotContainsString('user_project_evaluations', $source, $path);
        }
    }

    public function test_revision_reader_selects_one_latest_canonical_submission_per_logical_project(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/app/Services/CourseRevisionLearnerReadService.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('ProjectSubmission::query()', $source);
        self::assertStringContainsString("->selectRaw('MAX(id)')", $source);
        self::assertStringNotContainsString('latestReviewDecision', $source);
        self::assertStringContainsString("->reviewOutcome()['passed']", $source);
    }

    public function test_project_review_cannot_mutate_an_issued_certificate_claim(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/app/Services/ProjectSubmissionService.php'
        );

        self::assertIsString($source);
        self::assertStringNotContainsString('use App\\Models\\Certificate;', $source);
        self::assertStringNotContainsString('Certificate::query()', $source);
        self::assertStringNotContainsString("verification_level' =>", $source);
    }
}
