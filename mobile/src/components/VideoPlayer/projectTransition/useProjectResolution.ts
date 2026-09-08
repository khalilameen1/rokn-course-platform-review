import {useCallback, useEffect, useRef, useState} from 'react';

import {
  loadProjectResolution,
  retryProjectReport,
  retryProjectReview,
  type ProjectSubmissionOutcome,
} from '../courseLearningApi';
import type {
  CourseProject,
  ProjectFeedbackThread,
  ProjectReportStatus,
  ProjectStatus,
} from '../types';
import {reviewFeedbackForStatus} from '../courseLearning/projectJourney';
import {
  captureAccountSessionBoundary,
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';

type ProjectResolution = Awaited<ReturnType<typeof loadProjectResolution>>;

export type ProjectRuntimeContract = {
  canSubmit: boolean;
  canContinue: boolean;
  feedbackLevel: 'pass_only' | 'report' | 'enhanced';
  reportEnabled: boolean;
  replyEnabled: boolean;
  canRetryReport: boolean;
  reportRetryEndpoint?: string;
  canRetryReview: boolean;
  reviewRetryEndpoint?: string;
  reviewFailureCategory?: string;
};

type ProjectResolutionState = {
  status: ProjectStatus;
  reportStatus: ProjectReportStatus;
  reviewFeedback?: string;
  feedbackThread?: ProjectFeedbackThread;
  contract: ProjectRuntimeContract;
};

type ContractSource = {
  canSubmit?: boolean;
  canContinue?: boolean;
  feedbackLevel?: 'pass_only' | 'report' | 'enhanced';
  reportEnabled?: boolean;
  replyEnabled?: boolean;
  canRetryReport?: boolean;
  reportRetryEndpoint?: string;
  canRetryReview?: boolean;
  reviewRetryEndpoint?: string;
  reviewFailureCategory?: string;
};

const runtimeContract = (source: ContractSource): ProjectRuntimeContract => ({
  // Missing access flags fail closed. The canonical API always sends them;
  // treating omission as permission made an incomplete payload actionable.
  canSubmit: source.canSubmit === true,
  canContinue: source.canContinue === true,
  feedbackLevel: source.feedbackLevel ?? 'pass_only',
  reportEnabled: source.reportEnabled === true,
  replyEnabled:
    source.feedbackLevel === 'enhanced' && source.replyEnabled === true,
  canRetryReport: source.canRetryReport === true,
  reportRetryEndpoint: source.reportRetryEndpoint,
  canRetryReview: source.canRetryReview === true,
  reviewRetryEndpoint: source.reviewRetryEndpoint,
  reviewFailureCategory: source.reviewFailureCategory,
});

const stateFromProject = (project: CourseProject): ProjectResolutionState => {
  const contract = runtimeContract(project);
  return {
    status: project.status,
    reportStatus:
      project.reportStatus ??
      (contract.reportEnabled ? 'not_requested' : 'not_included'),
    reviewFeedback: reviewFeedbackForStatus(
      project.status,
      project.reviewFeedback,
    ),
    feedbackThread: project.feedbackThread,
    contract,
  };
};

const stateFromResolution = (
  resolution: ProjectResolution,
): ProjectResolutionState => ({
  status: resolution.status,
  reportStatus: resolution.reportStatus,
  reviewFeedback: reviewFeedbackForStatus(
    resolution.status,
    resolution.reviewFeedback,
  ),
  feedbackThread: resolution.feedbackThread ?? undefined,
  contract: runtimeContract(resolution),
});

export const useProjectResolution = ({
  active,
  appIsActive,
  project,
  onReviewResolution,
}: {
  active: boolean;
  appIsActive: boolean;
  project: CourseProject;
  onReviewResolution?: (resolution: ProjectResolution) => void;
}) => {
  const activeProjectIdRef = useRef(project.id);
  activeProjectIdRef.current = project.id;
  const [resolution, setResolution] = useState<ProjectResolutionState>(() =>
    stateFromProject(project),
  );
  const [reportRetrying, setReportRetrying] = useState(false);
  const retryFlightRef = useRef<symbol | null>(null);
  const reviewFlightRef = useRef<symbol | null>(null);
  const reviewReadOnlyRef = useRef(false);
  const [reviewRetrying, setReviewRetrying] = useState(false);
  const [reviewRecoveryRequired, setReviewRecoveryRequired] = useState(false);
  const [reviewRecoveryError, setReviewRecoveryError] = useState('');
  const pollJitterRef = useRef(0.82 + Math.random() * 0.3);

  const ownsProject = useCallback(
    (projectId: string) => activeProjectIdRef.current === projectId,
    [],
  );

  const applyResolution = useCallback((next: ProjectResolution) => {
    setResolution(stateFromResolution(next));
  }, []);

  useEffect(() => {
    const contract = runtimeContract({
      canSubmit: project.canSubmit,
      canContinue: project.canContinue,
      feedbackLevel: project.feedbackLevel,
      reportEnabled: project.reportEnabled,
      replyEnabled: project.replyEnabled,
      canRetryReport: project.canRetryReport,
      reportRetryEndpoint: project.reportRetryEndpoint,
      canRetryReview: project.canRetryReview,
      reviewRetryEndpoint: project.reviewRetryEndpoint,
      reviewFailureCategory: project.reviewFailureCategory,
    });
    setResolution({
      status: project.status,
      reportStatus:
        project.reportStatus ??
        (contract.reportEnabled ? 'not_requested' : 'not_included'),
      reviewFeedback: reviewFeedbackForStatus(
        project.status,
        project.reviewFeedback,
      ),
      feedbackThread: project.feedbackThread,
      contract,
    });
    retryFlightRef.current = null;
    setReportRetrying(false);
  }, [
    project.canContinue,
    project.canRetryReport,
    project.canRetryReview,
    project.reviewRetryEndpoint,
    project.reviewFailureCategory,
    project.canSubmit,
    project.feedbackLevel,
    project.feedbackThread,
    project.id,
    project.replyEnabled,
    project.reportEnabled,
    project.reportRetryEndpoint,
    project.reportStatus,
    project.reviewFeedback,
    project.status,
  ]);

  useEffect(() => {
    reviewReadOnlyRef.current = false;
    setReviewRecoveryRequired(false);
    setReviewRecoveryError('');
    setReviewRetrying(false);
    return () => {
      retryFlightRef.current = null;
      reviewFlightRef.current = null;
    };
  }, [project.id]);

  useEffect(() => {
    if (
      !active ||
      !appIsActive ||
      resolution.status !== 'review_unavailable' ||
      resolution.contract.canRetryReview
    ) {
      return;
    }
    const projectId = project.id;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let attempts = 0;
    const ownsRead = () => !cancelled && ownsProject(projectId);
    const schedule = () => {
      attempts += 1;
      timer = setTimeout(
        () => void refresh(),
        attempts < 6 ? 5000 : 30000,
      );
    };
    const refresh = async () => {
      if (reviewFlightRef.current) {
        schedule();
        return;
      }
      try {
        const next = await loadProjectResolution(projectId);
        if (!ownsRead()) return;
        if (next.status !== 'review_unavailable') {
          // The refreshed course map, not this small resolution response,
          // owns the newly unlocked content and signed media.
          applyResolution({...next, canContinue: false});
          onReviewResolution?.(next);
          return;
        }
        applyResolution(next);
      } catch {}
      if (ownsRead()) schedule();
    };
    void refresh();
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [
    active,
    appIsActive,
    applyResolution,
    onReviewResolution,
    ownsProject,
    project.id,
    resolution.contract.canRetryReview,
    resolution.status,
  ]);

  useEffect(() => {
    if (
      !active ||
      !appIsActive ||
      resolution.status !== 'passed' ||
      !resolution.contract.reportEnabled ||
      resolution.reportStatus !== 'queued'
    ) {
      return;
    }

    const projectId = project.id;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let attempts = 0;
    const schedule = (minimumMs: number) => {
      attempts += 1;
      const backoff =
        attempts > 30
          ? 30000
          : Math.min(
              12000,
              minimumMs * Math.pow(1.45, Math.min(8, attempts - 1)),
            );
      timer = setTimeout(
        () => void refresh(),
        Math.round(backoff * pollJitterRef.current),
      );
    };
    const refresh = async () => {
      try {
        const next = await loadProjectResolution(projectId);
        if (cancelled || !ownsProject(projectId)) return;
        applyResolution(next);
        if (next.reportStatus === 'queued') schedule(2200);
      } catch {
        if (!cancelled && ownsProject(projectId)) {
          schedule(3500);
        }
      }
    };
    void refresh();
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [
    active,
    appIsActive,
    applyResolution,
    ownsProject,
    project.id,
    resolution.contract.reportEnabled,
    resolution.reportStatus,
    resolution.status,
  ]);

  useEffect(() => {
    if (
      !active ||
      !appIsActive ||
      resolution.status !== 'passed' ||
      !resolution.contract.reportEnabled ||
      resolution.reportStatus !== 'failed' ||
      resolution.contract.canRetryReport
    ) {
      return;
    }
    const projectId = project.id;
    let cancelled = false;
    void loadProjectResolution(projectId)
      .then(next => {
        if (!cancelled && ownsProject(projectId)) applyResolution(next);
      })
      .catch(() => undefined);
    return () => {
      cancelled = true;
    };
  }, [
    active,
    appIsActive,
    applyResolution,
    ownsProject,
    project.id,
    resolution.contract.canRetryReport,
    resolution.contract.reportEnabled,
    resolution.reportStatus,
    resolution.status,
  ]);

  const applySubmissionOutcome = useCallback(
    (outcome: ProjectSubmissionOutcome) => {
      if (!outcome.accepted) return;
      setResolution(current => ({
        ...current,
        status: outcome.submissionStatus,
        contract: {
          ...current.contract,
          canSubmit: outcome.submissionStatus === 'needs_changes',
          canContinue:
            outcome.submissionStatus === 'passed' && outcome.canContinue,
          canRetryReview: outcome.canRetryReview === true,
          reviewRetryEndpoint: outcome.reviewRetryEndpoint,
          reviewFailureCategory: outcome.reviewFailureCategory,
        },
        reportStatus:
          outcome.submissionStatus === 'passed' &&
          current.contract.reportEnabled
            ? 'queued'
            : outcome.submissionStatus === 'needs_changes'
            ? 'not_requested'
            : current.reportStatus,
        reviewFeedback: reviewFeedbackForStatus(
          outcome.submissionStatus,
          outcome.reviewFeedback,
        ),
      }));
    },
    [],
  );

  const retryReport = useCallback(async () => {
    const {canRetryReport, reportRetryEndpoint} = resolution.contract;
    if (!reportRetryEndpoint || !canRetryReport || retryFlightRef.current) {
      return;
    }
    const projectId = project.id;
    const flight = Symbol('project-report-retry');
    retryFlightRef.current = flight;
    const ownsFlight = () =>
      ownsProject(projectId) && retryFlightRef.current === flight;
    setReportRetrying(true);
    let boundary: AccountSessionBoundary | undefined;
    try {
      boundary = await captureAccountSessionBoundary();
      if (!ownsFlight()) return;
      const next = await retryProjectReport(reportRetryEndpoint);
      assertAccountSessionBoundary(boundary);
      // Only the committed retry response starts polling. Reading while the
      // POST is still pending can return the previous failure and stop it.
      if (ownsFlight()) applyResolution(next);
    } catch {
      try {
        if (!boundary || !ownsFlight()) return;
        assertAccountSessionBoundary(boundary);
        const next = await loadProjectResolution(projectId);
        assertAccountSessionBoundary(boundary);
        if (ownsFlight()) applyResolution(next);
      } catch {
        // Keep the last confirmed failure if recovery is also unavailable.
        // A lost ACK may be queued; a later explicit retry reconciles it.
      }
    } finally {
      if (ownsFlight()) {
        retryFlightRef.current = null;
        setReportRetrying(false);
      }
    }
  }, [applyResolution, ownsProject, project.id, resolution.contract]);

  const retryReview = useCallback(async () => {
    const {canRetryReview, reviewRetryEndpoint} = resolution.contract;
    if (
      !active ||
      !appIsActive ||
      reviewFlightRef.current ||
      (!reviewReadOnlyRef.current &&
        (!canRetryReview ||
          !reviewRetryEndpoint ||
          resolution.status !== 'review_unavailable'))
    )
      return;
    const projectId = project.id;
    const flight = Symbol('project-review-retry');
    reviewFlightRef.current = flight;
    const ownsFlight = () =>
      ownsProject(projectId) && reviewFlightRef.current === flight;
    setReviewRetrying(true);
    setReviewRecoveryError('');
    const apply = (next: ProjectResolution) => {
      if (!ownsFlight()) return;
      reviewReadOnlyRef.current = false;
      setReviewRecoveryRequired(false);
      // A pass needs the course's fresh media entitlement before continuing.
      applyResolution({...next, canContinue: false});
      onReviewResolution?.(next);
    };
    let boundary: AccountSessionBoundary | undefined;
    try {
      boundary = await captureAccountSessionBoundary();
      if (!ownsFlight()) return;
      const next = reviewReadOnlyRef.current
        ? await loadProjectResolution(projectId)
        : await retryProjectReview(reviewRetryEndpoint!);
      assertAccountSessionBoundary(boundary);
      apply(next);
    } catch {
      // A timed-out POST may already be queued. Read the existing submission;
      // never turn an uncertain retry into a second review request.
      try {
        if (!boundary || !ownsFlight()) return;
        assertAccountSessionBoundary(boundary);
        const next = await loadProjectResolution(projectId);
        assertAccountSessionBoundary(boundary);
        apply(next);
      } catch {
        if (ownsFlight()) {
          reviewReadOnlyRef.current = true;
          setReviewRecoveryRequired(true);
          setReviewRecoveryError('تعذّر تحديث حالة المراجعة  تسليمك محفوظ');
        }
      }
    } finally {
      if (ownsFlight()) {
        reviewFlightRef.current = null;
        setReviewRetrying(false);
      }
    }
  }, [
    active,
    appIsActive,
    applyResolution,
    onReviewResolution,
    ownsProject,
    project.id,
    resolution.contract,
    resolution.status,
  ]);

  return {
    ...resolution,
    applySubmissionOutcome,
    reportRetryAvailable:
      resolution.contract.canRetryReport &&
      Boolean(resolution.contract.reportRetryEndpoint),
    reportRetrying,
    retryReport,
    reviewRetryAvailable:
      resolution.contract.canRetryReview &&
      Boolean(resolution.contract.reviewRetryEndpoint),
    reviewRetrying,
    reviewRecoveryRequired,
    reviewRecoveryError,
    retryReview,
  };
};
