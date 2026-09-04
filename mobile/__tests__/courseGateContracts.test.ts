import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('course gate contracts', () => {
  it('keeps purchase and progression lock reasons in the API contract', () => {
    const baseResource = source(
      '../backend/app/Http/Resources/BaseCourseResource.php',
    );
    const learningResource = source(
      '../backend/app/Http/Resources/CourseResource.php',
    );
    const mapping = source(
      'src/components/VideoPlayer/courseLearning/mapping.ts',
    );

    expect(baseResource).toContain(
      "'lock_reason' => $isPreview ? null : 'course_purchase_required'",
    );
    expect(baseResource).not.toContain(
      "'sections' => $this->whenLoaded('sections'",
    );
    expect(learningResource).not.toContain("$baseData['sections'] =");
    expect(learningResource).toContain("['lock_reason'] ?? null");
    expect(mapping).toContain('valueAsString(section.lock_reason)');
  });

  it('does not render a public payload as an enrolled project-gated course', () => {
    const loader = source('src/screens/reels/useReelsCourseLoader.ts');
    expect(loader).toContain("accessType === 'none'");
    expect(loader).toContain("navigation.replace('CourseDetails'");
    expect(loader).toContain('const requestedAnchor = reloadTarget');
    expect(loader).toContain('reelId: params.reelId');
    expect(loader).toContain('lessonId: params.lessonId');
    expect(loader).toContain('projectId: params.projectId');
    expect(loader).toContain('resolveReelsFeedAnchor(');
  });

  it('keeps project portfolio publication explicit and entitlement gated', () => {
    const portfolioController = source(
      '../backend/app/Http/Controllers/API/PortfolioController.php',
    );
    const portfolioMutations = source(
      '../backend/app/Services/PortfolioMediaMutationService.php',
    );
    const portfolioVideoUploads = source(
      '../backend/app/Services/PortfolioVideoUploadService.php',
    );
    const portfolioState = source('src/screens/Profile/portfolioState.ts');

    expect(portfolioController).toContain('submissionAllowsPortfolio');
    expect(portfolioController).toContain("'is_public' => false");
    expect(portfolioController).not.toContain(
      "forceFill(['is_public' => true])",
    );
    expect(portfolioVideoUploads).not.toContain(
      "forceFill(['is_public' => true])",
    );
    expect(portfolioMutations).toContain('if ($uploadedCount < 1)');
    expect(portfolioState).toContain(
      'const hasUploadedWork = portfolioMediaCount(item) > 0',
    );
  });

  it('renders one entitlement-derived course CTA instead of a duplicate sticky action', () => {
    const screen = source('src/screens/CourseDetails/index.tsx');
    const coupon = source(
      'src/screens/CourseDetails/details/useCourseCoupon.ts',
    );
    expect(screen).toContain('primaryActionLabel={primaryActionLabel}');
    expect(screen).not.toContain('<StickyCourseAction');
    expect(screen).not.toContain('useStickyCourseAction');
    expect(coupon).toContain('quoteEpochRef.current += 1');
    expect(coupon).toContain('quoteEpochRef.current !== quoteEpoch');
    expect(screen).not.toContain('<CourseCodeRedemptionDialog');

    const dialogs = source(
      'src/screens/CourseDetails/details/PurchaseDialogs.tsx',
    );
    const dialogSteps = source(
      'src/screens/CourseDetails/details/PurchaseDialogSteps.tsx',
    );
    expect(dialogs).not.toContain('CourseCodeRedemptionDialog');
    expect(dialogs).toContain("dialogStep === 'plans'");
    expect(dialogSteps).toContain('export const CourseCodeEntry');
    expect(dialogSteps).toContain('<CourseCodeEntry');
  });

  it('keeps project report and conversation tiers distinct in the UI', () => {
    const transition = source(
      'src/components/VideoPlayer/ProjectTransition.tsx',
    );
    const feedback = source(
      'src/components/VideoPlayer/projectTransition/useProjectFeedback.ts',
    );
    const submission = source(
      'src/components/VideoPlayer/projectTransition/useProjectSubmission.ts',
    );
    const resolution = source(
      'src/components/VideoPlayer/projectTransition/useProjectResolution.ts',
    );
    const feedbackPanel = source(
      'src/components/VideoPlayer/projectTransition/ProjectFeedbackPanel.tsx',
    );

    expect(feedback).toContain("feedbackLevel === 'enhanced'");
    expect(feedbackPanel).toContain("feedbackLevel === 'report'");
    expect(feedbackPanel).toContain('الرد متاح في فئة المتابعة');
    expect(feedbackPanel).toContain('الردود متاحة في فئة المتابعة');
    expect(feedback).toContain('!canReply ||');
    expect(submission).toContain('if (outcome.accepted)');
    expect(submission).toContain('setDraftReady(false)');
    expect(submission).toContain('submissionAllowed: boolean');
    expect(submission).not.toContain('canSubmit ?? project.canSubmit');
    expect(transition).not.toContain('project.submissionAttachments');
    expect(submission).not.toContain('setStatus(');
    expect(resolution).toContain('retryFlightRef.current');
    expect(resolution).toContain('useState<ProjectResolutionState>');
    expect(resolution).toContain('if (!outcome.accepted) return');
    expect(resolution).toContain("outcome.submissionStatus === 'passed'");
    expect(resolution).toContain(
      "canSubmit: outcome.submissionStatus === 'needs_changes'",
    );
    expect(feedback).not.toContain('replyEnabled !== false');
    expect(feedback).toContain(
      'loadProjectFeedbackThread(projectId, threadId)',
    );
    expect(feedback).toContain('projectFeedbackThreadIsPending');

    const reelsController = source('src/screens/reels/useReelsController.tsx');
    expect(reelsController).toContain(
      "else if (result.submissionStatus === 'needs_changes')",
    );

    const courseMap = source('src/components/view/Module.tsx');
    expect(courseMap).not.toContain('result.passed && result.canContinue');
    expect(courseMap).toContain("navigation.navigate('Reels'");
    expect(courseMap).toContain('projectId: step.project.id');
    expect(courseMap).not.toContain('submitProjectAttempt');
    expect(courseMap).not.toContain('loadProjectFeedbackThread');

    const projectMapping = source(
      'src/components/VideoPlayer/courseLearning/projectMapping.ts',
    );
    for (const field of [
      'submission_status',
      'can_submit',
      'can_continue',
      'feedback_level',
      'report_enabled',
      'report_status',
      'reply_enabled',
      'can_retry_report',
      'report_retry_endpoint',
    ]) {
      expect(projectMapping).toContain(field);
    }
    expect(projectMapping).not.toContain('submissionAttachments:');
    expect(projectMapping).not.toContain('can_resubmit');
    expect(projectMapping).not.toContain('submission.status');
    expect(projectMapping).not.toContain('submission.passed');

    const projects = source(
      'src/components/VideoPlayer/courseLearning/projects.ts',
    );
    expect(projects).not.toContain('submission.attachments');
    expect(projects).not.toContain('payload?.passed');
    expect(projects).not.toContain('payload?.status');
    expect(projects).not.toContain('needs_resubmission');

    const types = source('src/components/VideoPlayer/types.ts');
    expect(types).not.toContain('submissionAttachments?:');
  });
});
