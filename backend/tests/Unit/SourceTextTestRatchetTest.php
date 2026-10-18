<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Tests that read application source and assert on substrings break on
 * refactors and prove nothing about behaviour. Existing ones are a baseline
 * (the PHPStan/ESLint baseline-file ratchet): they may be converted and
 * removed from this list, but no new test file may start doing it.
 */
final class SourceTextTestRatchetTest extends TestCase
{
    /** @var list<string> */
    private const BASELINE = [
        'tests/Feature/AdminAlertFeedbackTest.php',
        'tests/Feature/AdminDashboardListsAndExportsTest.php',
        'tests/Feature/AdminHomeCurationTest.php',
        'tests/Feature/AdminMutableConfigurationConcurrencyTest.php',
        'tests/Feature/CourseHeroSelectionConcurrencyTest.php',
        'tests/Feature/BunnyUploadSafetyTest.php',
        'tests/Feature/OperatingInvoiceEntryContractTest.php',
        'tests/Feature/PublicCertificateIntegrityTest.php',
        'tests/Unit/AdminCourseOperationsViewTest.php',
        'tests/Unit/AdminCourseSectionStateContractTest.php',
        'tests/Unit/AdminDashboardViewTest.php',
        'tests/Unit/AdminEconomyDashboardContractTest.php',
        'tests/Unit/AdminFinanceViewsTest.php',
        'tests/Unit/AdminInlineStyleContractTest.php',
        'tests/Unit/AdminLearningViewsTest.php',
        'tests/Unit/AdminNotificationAuthoringContractTest.php',
        'tests/Unit/AdminPaymentOperationsContractTest.php',
        'tests/Unit/AdminStudentWorkspaceContractTest.php',
        'tests/Unit/AdminTransactionBoundaryTest.php',
        'tests/Unit/CourseAuthoringPolicyTest.php',
        'tests/Unit/CourseResourceProjectSubmissionContractTest.php',
        'tests/Unit/LessonMediaDeliveryContractTest.php',
        'tests/Unit/ModeratorCourseWorkspaceContractTest.php',
        'tests/Unit/ProductionQueueTopologyTest.php',
        'tests/Unit/ProjectReportRetryContractTest.php',
        'tests/Unit/ProjectSubmissionCanonicalStateContractTest.php',
        'tests/Unit/ProjectSubmissionFileRetentionContractTest.php',
        'tests/Unit/PublicDocumentsTest.php',
        'tests/Unit/RedactSensitiveContextTest.php',
    ];

    public function test_no_new_test_asserts_on_application_source_text(): void
    {
        $root = dirname(__DIR__, 2);
        $readsSource = '/(file_get_contents|File::get)\(\s*(app_path|resource_path|base_path|dirname\(__DIR__|__DIR__)/';
        $sourcePath = '/app_path\(|resource_path\(|[\'"]\/?app\/|\/resources\/views|[\'"]\/?routes\/(web|api)/';
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests'));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = 'tests/'.str_replace('\\', '/', substr($file->getPathname(), strlen($root.'/tests') + 1));
            if ($relative === 'tests/Unit/SourceTextTestRatchetTest.php' || in_array($relative, self::BASELINE, true)) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match($readsSource, $contents) === 1 && preg_match($sourcePath, $contents) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Test behaviour through HTTP, services or rendered views instead of reading source text.'
        );
    }
}
