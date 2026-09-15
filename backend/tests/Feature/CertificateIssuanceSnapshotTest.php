<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\AppFrontNameSpace;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\CertificateIssuanceSnapshotService;
use App\Services\CertificateQrDestinationService;
use App\Services\CertificateTextTemplateService;
use App\Services\PortfolioModerationService;
use App\Support\RoknPublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CertificateIssuanceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_legacy_keys_use_the_same_new_prefix_without_fabricating_projects(): void
    {
        $user = $this->student();
        $course = $this->course();
        foreach (['completion', 'knowledge', 'applied', 'skills', 'projects'] as $key) {
            $course->forceFill(['certificate_text_template_key' => $key])->save();
            $snapshot = $this->snapshot($user, $course);
            self::assertSame($key, $snapshot['certificate_text_template_key']);
            self::assertSame('أتم كورس', $snapshot['certificate_text']);
            self::assertSame('', $snapshot['certificate_completion_text']);
            self::assertSame([], $snapshot['certificate_project_evidence']);
            self::assertSame('certificate', $snapshot['certificate_qr_snapshot']['type']);
        }
        self::assertNull($user->fresh()->portfolio_slug);
        $course->certificate_text_template_key = 'unrecognized';
        self::assertNull(app(CertificateIssuanceSnapshotService::class)
            ->forIssuance($user, $course, (string) Str::uuid()));
    }

    public function test_authoring_preview_uses_actual_passage_projects_not_the_selected_key(): void
    {
        $course = $this->course('مشروع تخرج فقط');
        $course->forceFill(['certificate_text_template_key' => 'projects'])->save();
        $this->project($course, true);
        $service = app(CertificateIssuanceSnapshotService::class);
        self::assertSame('', $service->forPreview($course)['certificate_completion_text']);
        $this->project($course);
        $course->forceFill(['certificate_text_template_key' => 'knowledge'])->save();
        self::assertSame('واجتاز مشروعاته', $service->forPreview($course)['certificate_completion_text']);
        self::assertSame('أتم كورس', $service->forPreview($course)['certificate_text']);
        self::assertSame('editorial_v1', $service->forPreview($course)['certificate_design_version']);
    }

    public function test_claim_requires_every_project_to_have_passed_before_earned_completion(): void
    {
        $user = $this->student();
        $course = $this->course();
        $this->earned($user, $course);
        $passage = $this->project($course);
        $graduation = $this->project($course, true);
        $passed = $this->submission($user, $passage);
        self::assertSame('', $this->snapshot($user, $course)['certificate_completion_text']);

        $graduated = $this->submission($user, $graduation);
        $snapshot = $this->snapshot($user, $course);
        self::assertSame('واجتاز مشروعاته', $snapshot['certificate_completion_text']);
        self::assertSame(1, $snapshot['certificate_curriculum_revision']);
        self::assertEqualsCanonicalizing([
            ['project_id' => (int) $passage->id, 'submission_id' => (int) $passed->id],
            ['project_id' => (int) $graduation->id, 'submission_id' => (int) $graduated->id],
        ], $snapshot['certificate_project_evidence']);
    }

    public function test_other_learners_progress_and_unreviewed_or_late_results_are_not_evidence(): void
    {
        $user = $this->student();
        $course = $this->course();
        $this->earned($user, $course);
        $project = $this->project($course);
        $this->submission($this->student(), $project);
        self::assertSame('', $this->snapshot($user, $course)['certificate_completion_text']);

        $submission = $this->submission($user, $project);
        $submission->forceFill(['reviewed_at' => now()])->save();
        self::assertSame('', $this->snapshot($user, $course)['certificate_completion_text']);
        $submission->forceFill(['reviewed_at' => null])->save();
        self::assertSame('', $this->snapshot($user, $course)['certificate_completion_text']);
        $submission->forceFill([
            'reviewed_at' => now()->subHours(2),
            'review_status' => ProjectSubmission::STATUS_NEEDS_RESUBMISSION,
        ])->save();
        self::assertSame('', $this->snapshot($user, $course)['certificate_completion_text']);
    }

    public function test_latest_attempt_at_completion_is_authoritative_but_later_attempts_do_not_rewrite_it(): void
    {
        $user = $this->student();
        $course = $this->course();
        $this->earned($user, $course);
        $project = $this->project($course);
        $this->submission($user, $project);
        $later = $this->submission($user, $project);
        $later->forceFill(['review_status' => ProjectSubmission::STATUS_PENDING])->save();
        self::assertSame('', $this->snapshot($user, $course)['certificate_completion_text']);
        $later->forceFill(['submitted_at' => now(), 'reviewed_at' => null])->save();
        self::assertSame('واجتاز مشروعاته', $this->snapshot($user, $course)['certificate_completion_text']);
    }

    public function test_archived_earned_graph_is_used_after_the_current_course_changes(): void
    {
        $user = $this->student();
        $current = $this->course('النسخة الجديدة', 2);
        $old = $this->course('النسخة المنجزة', 1);
        $this->archive($current, $old);
        $this->earned($user, $current, 1);
        $oldProject = $this->project($old);
        $this->submission($user, $oldProject);
        $this->project($current); // This newer, unpassed requirement is not the earned graph.
        self::assertSame('واجتاز مشروعاته', $this->snapshot($user, $current)['certificate_completion_text']);

        $differentUser = $this->student();
        $differentCurrent = $this->course('أضيفت مشروعات لاحقًا', 2);
        $oldTheory = $this->course('النسخة القديمة بلا مشروعات', 1);
        $this->archive($differentCurrent, $oldTheory);
        $this->earned($differentUser, $differentCurrent, 1);
        $this->submission($differentUser, $this->project($differentCurrent));
        self::assertSame('', $this->snapshot($differentUser, $differentCurrent)['certificate_completion_text']);
    }

    public function test_missing_earned_graph_and_watch_only_completion_never_gain_project_claims(): void
    {
        $user = $this->student();
        $course = $this->course('نسخة حالية', 2);
        $this->earned($user, $course, 1);
        $this->submission($user, $this->project($course));
        self::assertSame('', $this->snapshot($user, $course)['certificate_completion_text']);

        $watcher = $this->student();
        $watchCourse = $this->course();
        $this->earned($watcher, $watchCourse, 1, false);
        $this->submission($watcher, $this->project($watchCourse));
        $snapshot = $this->snapshot($watcher, $watchCourse);
        self::assertSame('', $snapshot['certificate_completion_text']);
        self::assertNull($snapshot['certificate_curriculum_revision']);
    }

    public function test_project_evidence_crosses_only_explicit_learner_continuity_mappings(): void
    {
        $user = $this->student();
        $current = $this->course('النسخة المنجزة', 2);
        $old = $this->course('النسخة السابقة', 1);
        $this->archive($current, $old);
        $currentProject = $this->project($current);
        $oldProject = $this->project($old);
        $this->earned($user, $current, 2);
        $this->submission($user, $oldProject);
        $revision = CourseAuthoringRevision::query()->where('canonical_course_id', $current->id)->firstOrFail();
        DB::table('course_authoring_revision_entities')->insert([
            'course_authoring_revision_id' => $revision->id,
            'entity_type' => Project::class,
            'source_entity_id' => $oldProject->id,
            'revision_entity_id' => $currentProject->id,
            'survives_publish' => true,
            'carries_learner_state' => true,
            'learner_root_entity_id' => $oldProject->id,
        ]);
        self::assertSame('واجتاز مشروعاته', $this->snapshot($user, $current)['certificate_completion_text']);
        DB::table('course_authoring_revision_entities')->where('course_authoring_revision_id', $revision->id)
            ->update(['carries_learner_state' => false]);
        self::assertSame('', $this->snapshot($user, $current)['certificate_completion_text']);
    }

    public function test_new_qr_uses_only_existing_nonempty_approved_public_work(): void
    {
        $user = $this->student();
        $user->forceFill(['portfolio_slug' => 'rokn-'.strtolower(Str::random(24))])->save();
        $service = app(CertificateQrDestinationService::class);
        $publicId = (string) Str::uuid();
        $this->approve($user);
        self::assertSame('certificate', $service->forIssuance($user->fresh(), $publicId)['type']);

        $this->portfolioItem($user, false);
        $this->approve($user);
        self::assertSame('certificate', $service->forIssuance($user->fresh(), $publicId)['type']);
        $publicWork = $this->portfolioItem($user, true);
        self::assertSame('certificate', $service->forIssuance($user->fresh(), $publicId)['type']);
        $this->approve($user);
        $destination = $service->forIssuance($user->fresh(), $publicId);
        self::assertSame('portfolio', $destination['type']);
        self::assertSame(RoknPublicUrl::portfolio($user->portfolio_slug), $destination['url']);

        DB::table('portfolio_items')->where('id', $publicWork->id)->update(['title' => 'unreviewed change']);
        self::assertSame('certificate', $service->forIssuance($user->fresh(), $publicId)['type']);
        $this->approve($user);
        $user->forceFill(['portfolio_sharing_suspended_at' => now()])->save();
        self::assertSame('certificate', $service->forIssuance($user->fresh(), $publicId)['type']);
    }

    public function test_new_snapshots_keep_empty_claims_and_qr_targets_immutable_on_recovery(): void
    {
        $user = $this->student();
        $course = $this->course();
        $publicId = (string) Str::uuid();
        $snapshot = app(CertificateIssuanceSnapshotService::class)->forIssuance($user, $course, $publicId);
        $certificate = $this->credential($user, $course, $publicId, $snapshot);
        $destination = $snapshot['certificate_qr_snapshot'];
        $certificate->forceFill([
            'certificate_design_version' => 'different',
            'certificate_completion_text' => CertificateTextTemplateService::PROJECTS_COMPLETION,
            'certificate_curriculum_revision' => 99,
            'certificate_project_evidence' => [['project_id' => 1, 'submission_id' => 1]],
            'certificate_qr_snapshot' => ['type' => 'portfolio', 'url' => 'https://other.test', 'title' => 'changed', 'hint' => 'changed'],
        ])->save();
        $certificate = $certificate->fresh();
        self::assertSame('editorial_v1', $certificate->certificate_design_version);
        self::assertSame('', $certificate->certificate_completion_text);
        self::assertNull($certificate->certificate_curriculum_revision);
        self::assertSame([], $certificate->certificate_project_evidence);
        self::assertSame($destination, $certificate->certificate_qr_snapshot);
        self::assertSame($destination, app(CertificateQrDestinationService::class)->for($certificate));
        self::assertSame('pending', $certificate->image_path);
    }

    public function test_portfolio_qr_does_not_switch_after_moderation_or_slug_changes(): void
    {
        $user = $this->student();
        $course = $this->course();
        $user->forceFill(['portfolio_slug' => 'rokn-'.strtolower(Str::random(24))])->save();
        $this->portfolioItem($user, true);
        $this->approve($user);
        $publicId = (string) Str::uuid();
        $snapshot = app(CertificateIssuanceSnapshotService::class)->forIssuance($user->fresh(), $course, $publicId);
        $certificate = $this->credential($user, $course, $publicId, $snapshot);
        self::assertSame('portfolio', $snapshot['certificate_qr_snapshot']['type']);
        $user->forceFill([
            'portfolio_slug' => 'rokn-'.strtolower(Str::random(24)),
            'portfolio_sharing_status' => 'rejected',
            'portfolio_sharing_suspended_at' => now(),
        ])->save();
        self::assertSame($snapshot['certificate_qr_snapshot'], app(CertificateQrDestinationService::class)->for($certificate->fresh()));
    }

    public function test_legacy_credentials_keep_original_text_qr_rules_and_null_new_snapshot(): void
    {
        $user = $this->student();
        $course = $this->course();
        $user->forceFill(['portfolio_slug' => 'rokn-'.strtolower(Str::random(24))])->save();
        $legacy = $this->credential($user, $course, (string) Str::uuid(), [
            'certificate_text_template_key' => 'skills',
            'certificate_text' => 'تقديرًا لإتمام التدريب العملي في كورس',
        ]);
        $oldDestination = app(CertificateQrDestinationService::class)->for($legacy);
        self::assertSame('portfolio', $oldDestination['type']);
        $legacy->forceFill([
            'certificate_text' => 'أتم كورس',
            'certificate_design_version' => 'editorial_v1',
            'certificate_qr_snapshot' => ['type' => 'certificate'],
            'certificate_completion_text' => 'واجتاز مشروعاته',
        ])->save();
        self::assertNull($legacy->fresh()->certificate_design_version);
        self::assertNull($legacy->fresh()->certificate_qr_snapshot);
        self::assertNull($legacy->fresh()->certificate_completion_text);
        self::assertSame('تقديرًا لإتمام التدريب العملي في كورس', $legacy->fresh()->certificate_text);
        self::assertSame($oldDestination, app(CertificateQrDestinationService::class)->for($legacy->fresh()));
    }

    public function test_new_credential_with_missing_qr_snapshot_does_not_invent_a_target_on_recovery(): void
    {
        $certificate = new Certificate();
        $certificate->forceFill([
            'public_id' => (string) Str::uuid(),
            'certificate_design_version' => 'editorial_v1',
            'certificate_text_template_key' => 'skills',
            'certificate_qr_snapshot' => null,
        ]);
        self::assertNull(app(CertificateQrDestinationService::class)->for($certificate));
    }

    public function test_api_and_public_verification_show_only_the_issued_completion_snapshot(): void
    {
        $this->withoutMiddleware([AppFrontNameSpace::class, WebsiteVisitorCount::class]);
        $user = $this->student();
        $course = $this->course('كورس الشهادة المصدرة');
        $this->earned($user, $course);
        $this->submission($user, $this->project($course));
        $publicId = (string) Str::uuid();
        $snapshot = app(CertificateIssuanceSnapshotService::class)->forIssuance($user, $course, $publicId);
        $certificate = $this->credential($user, $course, $publicId, $snapshot);
        $payload = (new CertificateResource($certificate))->resolve();
        self::assertSame('أتم كورس', $payload['certificate_text']);
        self::assertSame('واجتاز مشروعاته', $payload['certificate_completion_text']);
        self::assertSame('editorial_v1', $payload['certificate_design_version']);
        $course->forceFill(['name_ar' => 'اسم لاحق', 'certificate_text_template_key' => 'knowledge'])->save();
        $this->get('/c/'.$publicId)->assertOk()
            ->assertSee('أتم كورس')->assertSee('واجتاز مشروعاته')
            ->assertSee('كورس الشهادة المصدرة')->assertDontSee('اسم لاحق');

        $other = $this->student();
        $withoutProjects = $this->course('كورس بلا مشروعات');
        $otherId = (string) Str::uuid();
        $plainSnapshot = app(CertificateIssuanceSnapshotService::class)->forIssuance($other, $withoutProjects, $otherId);
        $plain = $this->credential($other, $withoutProjects, $otherId, $plainSnapshot);
        self::assertSame('', (new CertificateResource($plain))->resolve()['certificate_completion_text']);
        $this->get('/c/'.$otherId)->assertOk()->assertSee('أتم كورس')->assertDontSee('واجتاز مشروعاته');
    }

    public function test_incomplete_or_inconsistent_new_snapshots_are_not_complete_credentials(): void
    {
        $user = $this->student();
        $course = $this->course();
        $publicId = (string) Str::uuid();
        $snapshot = app(CertificateIssuanceSnapshotService::class)->forIssuance($user, $course, $publicId);
        $certificate = $this->credential($user, $course, $publicId, $snapshot);
        self::assertTrue($certificate->hasCompleteCredentialSnapshot());
        $validQr = $snapshot['certificate_qr_snapshot'];
        $invalidStates = [
            ['certificate_design_version' => 'unknown'],
            ['certificate_text' => 'Unapproved wording'],
            ['certificate_completion_text' => null],
            ['certificate_completion_text' => 'Undocumented claim'],
            ['certificate_project_evidence' => null],
            ['certificate_project_evidence' => [['project_id' => 1, 'submission_id' => 2]]],
            ['certificate_curriculum_revision' => 0],
            ['certificate_completion_text' => 'واجتاز مشروعاته'],
            ['certificate_completion_text' => 'واجتاز مشروعاته', 'certificate_curriculum_revision' => 1],
            ['certificate_qr_snapshot' => null],
            ['certificate_qr_snapshot' => array_diff_key($validQr, ['hint' => true])],
            ['certificate_qr_snapshot' => array_replace($validQr, ['title' => ''])],
            ['certificate_qr_snapshot' => array_replace($validQr, ['type' => 'external'])],
            ['certificate_qr_snapshot' => array_replace($validQr, ['url' => 'https://rokn.app/c/'.Str::uuid()])],
            ['certificate_qr_snapshot' => array_replace($validQr, ['url' => 'javascript:alert(1)'])],
        ];
        foreach ($invalidStates as $attributes) {
            $invalid = clone $certificate;
            $invalid->forceFill($attributes);
            self::assertFalse($invalid->hasCompleteCredentialSnapshot(), json_encode($attributes));
            self::assertNull(app(CertificateQrDestinationService::class)->for($invalid));
        }

        $claimed = clone $certificate;
        $claimed->forceFill([
            'certificate_completion_text' => 'واجتاز مشروعاته',
            'certificate_curriculum_revision' => 1,
            'certificate_project_evidence' => [['project_id' => 1, 'submission_id' => 2]],
        ]);
        self::assertTrue($claimed->hasCompleteCredentialSnapshot());
        $claimed->certificate_project_evidence = [
            ['project_id' => 1, 'submission_id' => 2],
            ['project_id' => 1, 'submission_id' => 3],
        ];
        self::assertFalse($claimed->hasCompleteCredentialSnapshot());
    }

    private function student(): User
    {
        return User::query()->forceCreate([
            'name' => 'طالب ركن', 'email' => Str::uuid().'@rokn.test',
            'role' => 'client', 'active' => true,
        ]);
    }

    private function course(string $name = 'كورس الاختبار', int $revision = 1): Course
    {
        return Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => $name, 'price' => 0,
            'certificate_text_template_key' => 'completion',
            'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => $revision, 'last_published_authoring_version' => $revision,
            'published_at' => now()->subDays(2),
        ]);
    }

    private function earned(User $user, Course $course, int $revision = 1, bool $withProjects = true): void
    {
        CourseEnrollment::query()->forceCreate([
            'tenant_id' => 1, 'user_id' => $user->id, 'course_id' => $course->id,
            'enrolled_at' => now()->subDays(2), 'is_active' => true,
            'completed_curriculum_revision' => $revision,
            'curriculum_completed_at' => now()->subHour(),
            'completed_with_projects' => $withProjects,
        ]);
    }

    private function project(Course $course, bool $graduation = false): Project
    {
        $module = CourseModule::query()->firstOrCreate(
            ['course_id' => $course->id], ['title_ar' => 'الوحدة', 'order' => 1]
        );
        $project = Project::query()->create([
            'requirements_text_ar' => 'نفّذ المشروع', 'is_graduation_project' => $graduation,
        ]);
        CourseSection::query()->create([
            'course_id' => $course->id, 'module_id' => $module->id,
            'section_type' => 'project', 'sectionable_type' => Project::class,
            'sectionable_id' => $project->id, 'title_ar' => 'المشروع', 'order' => $project->id,
        ]);

        return $project;
    }

    private function submission(User $user, Project $project): ProjectSubmission
    {
        return ProjectSubmission::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $user->id, 'project_id' => $project->id,
            'idempotency_key' => (string) Str::uuid(),
            'review_status' => ProjectSubmission::STATUS_PASSED,
            'submitted_at' => now()->subHours(3), 'reviewed_at' => now()->subHours(2),
        ]);
    }

    private function archive(Course $current, Course $old): void
    {
        CourseAuthoringRevision::query()->create([
            'canonical_course_id' => $current->id, 'revision_course_id' => $old->id,
            'base_authoring_version' => 1, 'published_authoring_version' => 2,
            'status' => CourseAuthoringRevision::ARCHIVED,
            'clone_key' => (string) Str::uuid(), 'published_at' => now()->subMinutes(30),
        ]);
    }

    private function portfolioItem(User $user, bool $public): PortfolioItem
    {
        $item = PortfolioItem::query()->create([
            'user_id' => $user->id, 'title' => 'أعمالي', 'is_public' => $public,
        ]);
        PortfolioMedia::query()->create([
            'portfolio_item_id' => $item->id, 'file_path' => 'portfolio/example.jpg', 'file_type' => 'image',
        ]);

        return $item;
    }

    private function approve(User $user): void
    {
        $fresh = $user->fresh();
        $snapshot = app(PortfolioModerationService::class)->snapshot($fresh);
        $fresh->forceFill([
            'portfolio_sharing_status' => 'approved', 'portfolio_approved_hash' => $snapshot['hash'],
        ])->save();
    }

    private function snapshot(User $user, Course $course): array
    {
        return app(CertificateIssuanceSnapshotService::class)
            ->forIssuance($user->fresh(), $course->fresh(), (string) Str::uuid());
    }

    private function credential(User $user, Course $course, string $publicId, array $snapshot): Certificate
    {
        return Certificate::query()->create(array_merge([
            'public_id' => $publicId, 'user_id' => $user->id, 'course_id' => $course->id,
            'holder_name' => 'طالب ركن', 'course_name' => $course->name_ar,
            'generated_at' => now(), 'image_path' => 'pending', 'status' => 'active',
            'verification_level' => 'completion',
        ], $snapshot));
    }
}
