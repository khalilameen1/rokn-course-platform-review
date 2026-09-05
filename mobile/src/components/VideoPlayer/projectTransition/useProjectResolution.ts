import {useCallback, useEffect, useRef, useState} from 'react';

import {
  loadProjectResolution,
  retryProjectReport,
  type ProjectSubmissionOutcome,
} from '../courseLearningApi';
import type {
  CourseProject,
  ProjectFeedbackThread,
  ProjectReportStatus,
  ProjectStatus,
} from '../types';
import {reviewFeedbackForStatus} from '../courseLearning/projectJourney';

type ProjectResolution = Awaited<ReturnType<typeof loadProjectResolution>>;

export type ProjectRuntimeContract = {
  canSubmit: boolean;
  canContinue: boolean;
  feedbackLevel: 'pass_only' | 'report' | 'enhanced';
  reportEnabled: boolean;
  replyEnabled: boolean;
  canRetryReport: boolean;
  reportRetryEndpoint?: string;
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
}: {
  active: boolean;
  appIsActive: boolean;
  project: CourseProject;
}) => {
  const activeProjectIdRef = useRef(project.id);
  activeProjectIdRef.current = project.id;
  const [resolution, setResolution] = useState<ProjectResolutionState>(() =>
    stateFromProject(project),
  );
  const [reportRetrying, setReportRetrying] = useState(false);
  const retryFlightRef = useRef<symbol | null>(null);
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
      const backoff = Math.min(
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
        if (attempts < 30 && next.reportStatus === 'queued') {
          schedule(2200);
        }
      } catch {
        if (!cancelled && ownsProject(projectId) && attempts < 30) {
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
    setReportRetrying(true);
    setResolution(current => ({...current, reportStatus: 'queued'}));
    try {
      await retryProjectReport(reportRetryEndpoint);
    } catch {
      try {
        const next = await loadProjectResolution(projectId);
        if (ownsProject(projectId)) applyResolution(next);
      } catch {
        if (ownsProject(projectId)) {
          setResolution(current => ({...current, reportStatus: 'failed'}));
        }
      }
    } finally {
      if (retryFlightRef.current === flight) {
        retryFlightRef.current = null;
        if (ownsProject(projectId)) setReportRetrying(false);
      }
    }
  }, [applyResolution, ownsProject, project.id, resolution.contract]);

  return {
    ...resolution,
    applySubmissionOutcome,
    reportRetryAvailable:
      resolution.contract.canRetryReport &&
      Boolean(resolution.contract.reportRetryEndpoint),
    reportRetrying,
    retryReport,
  };
};
