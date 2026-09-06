<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Services\CourseAccessPlanService;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProjectSubmissionEvaluationSnapshotTest extends TestCase
{
    public function test_current_snapshot_survives_a_real_mysql_json_readback(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('Requires MySQL native JSON normalization.');
        }

        $snapshot = $this->snapshot();
        $row = DB::selectOne('SELECT CAST(? AS JSON) AS payload', [
            json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $readBack = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(4, $readBack['version']);
        self::assertSame($snapshot['fingerprint'], $readBack['fingerprint']);
        self::assertNotNull($this->read($readBack));
    }

    public function test_current_snapshot_survives_recursive_json_object_reordering(): void
    {
        $snapshot = $this->snapshot();
        self::assertSame(4, $snapshot['version']);
        $reordered = $this->reorderObjects($snapshot);
        self::assertNotSame(json_encode($snapshot), json_encode($reordered));
        self::assertNotNull($this->read($reordered));
        self::assertSame($snapshot['fingerprint'], $reordered['fingerprint']);
    }

    public function test_mysql_reordered_v3_is_verified_against_its_original_digest_without_rehashing(): void
    {
        $snapshot = $this->legacy($this->snapshot());
        $reordered = $this->reorderObjects($snapshot);
        self::assertNotSame($snapshot['fingerprint'], $this->legacyDigest($reordered));
        self::assertNotNull($this->read($reordered));
        self::assertSame($snapshot['fingerprint'], $this->read($reordered)['fingerprint']);
    }

    public function test_v3_captured_from_database_ordered_terms_also_verifies(): void
    {
        $snapshot = $this->snapshot();
        // Simulate the enrollment's native JSON object order before capture.
        $snapshot['access']['terms'] = $this->mysqlObjectOrder($snapshot['access']['terms']);
        $snapshot = $this->legacy($snapshot);
        $readBack = $this->mysqlObjectOrder($snapshot);
        self::assertNotSame($snapshot['fingerprint'], $this->legacyDigest($readBack));
        self::assertNotNull($this->read($readBack));
    }

    public function test_reordering_does_not_hide_changed_text_terms_or_added_fields(): void
    {
        foreach ([3, 4] as $version) {
            $snapshot = $this->snapshot();
            if ($version === 3) $snapshot = $this->legacy($snapshot);
            foreach (['requirements', 'terms', 'extra'] as $change) {
                $changed = $this->reorderObjects($snapshot);
                if ($change === 'requirements') $changed['project']['requirements_text'] = 'Different assignment';
                elseif ($change === 'terms') $changed['access']['terms']['price_coins'] = 101;
                else $changed['project']['unexpected'] = 'injected fact';
                self::assertNull($this->read($changed), "version {$version}, {$change}");
            }
        }
    }

    public function test_canonicalization_preserves_list_order_and_nested_objects(): void
    {
        $terms = $this->terms();
        $terms['extension'] = [['b' => 2, 'a' => 1], ['b' => 4, 'a' => 3]];
        $snapshot = $this->snapshot($terms);
        self::assertNotNull($this->read($this->reorderObjects($snapshot)));
        $snapshot['access']['terms']['extension'] = array_reverse($snapshot['access']['terms']['extension']);
        self::assertNull($this->read($snapshot));
    }

    public function test_unknown_legacy_digest_cannot_be_repaired_by_trusting_its_values(): void
    {
        $snapshot = $this->legacy($this->snapshot());
        $snapshot['fingerprint'] = str_repeat('0', 64);
        self::assertNull($this->read($this->reorderObjects($snapshot)));
    }

    private function read(array $snapshot): ?array
    {
        $submission = new ProjectSubmission();
        $submission->forceFill(['project_id' => 7, 'evaluation_snapshot' => $snapshot]);
        return ProjectSubmissionEvaluationSnapshot::fromSubmission($submission);
    }

    private function snapshot(?array $terms = null): array
    {
        $project = new Project();
        $project->setRawAttributes(['id' => 7, 'requirements_text_ar' => 'نفذ شعار <svg>شجرة</svg>',
            'requirements_text_en' => 'Draw a tree', 'updated_at' => '2026-09-06 00:00:00']);
        $course = new Course();
        $course->setRawAttributes(['id' => 3, 'name_ar' => 'كورس التصميم', 'name_en' => 'Design']);
        $section = new CourseSection();
        $section->setRawAttributes(['id' => 8, 'course_id' => 3, 'title' => 'Project',
            'title_ar' => 'المشروع', 'title_en' => 'Project']);
        $section->setRelation('course', $course);
        $enrollment = new CourseEnrollment();
        $enrollment->setRawAttributes(['id' => 9, 'access_plan_id' => 12]);
        return ProjectSubmissionEvaluationSnapshot::capture($project, $section, $enrollment, $terms ?? $this->terms());
    }

    private function terms(): array
    {
        $plan = new CourseAccessPlan();
        $plan->forceFill(['id' => 12, 'code' => 'basic', 'name_ar' => 'تعلم', 'price_coins' => 100,
            'project_feedback_level' => 'pass_only', 'max_output_tokens' => 320]);
        return app(CourseAccessPlanService::class)->snapshot($plan);
    }

    private function legacy(array $snapshot): array
    {
        $snapshot['version'] = 3;
        $snapshot['fingerprint'] = $this->legacyDigest($snapshot);
        return $snapshot;
    }

    private function legacyDigest(array $snapshot): string
    {
        unset($snapshot['fingerprint']);
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function reorderObjects(array $value): array
    {
        foreach ($value as $key => $child) {
            if (is_array($child)) $value[$key] = $this->reorderObjects($child);
        }
        return array_is_list($value) ? $value : array_reverse($value, true);
    }

    private function mysqlObjectOrder(array $value): array
    {
        foreach ($value as $key => $child) {
            if (is_array($child)) $value[$key] = $this->mysqlObjectOrder($child);
        }
        if (!array_is_list($value)) uksort($value, static fn ($a, $b): int => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
        return $value;
    }
}
