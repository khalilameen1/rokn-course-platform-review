import {useCallback, useEffect, useRef, useState} from 'react';
import {loadProjectFeedbackThread} from '../courseLearningApi';
import {projectFeedbackThreadIsPending} from '../projectFeedback/policy';
import type {ProjectFeedbackThread, ProjectReportStatus} from '../types';

// Owns the server transcript, access refresh and bounded polling. It does not
// read or write drafts, pick files, or initiate a paid message.
export const useProjectFeedbackThread = ({
  projectId,
  seedThread,
  active,
  appIsActive,
  feedbackLevel,
  replyEnabled,
  reportStatus,
}: {
  projectId: string;
  seedThread?: ProjectFeedbackThread;
  active: boolean;
  appIsActive: boolean;
  feedbackLevel: 'pass_only' | 'report' | 'enhanced';
  replyEnabled: boolean;
  reportStatus: ProjectReportStatus;
}) => {
  const activeProjectIdRef = useRef(projectId);
  activeProjectIdRef.current = projectId;
  const activeThreadIdRef = useRef<string | null>(seedThread?.id || null);
  const generationRef = useRef(0);
  const hydratedThreadRef = useRef<string | null>(null);
  const accessKey = `${feedbackLevel}:${replyEnabled}`;
  const hydratedAccessRef = useRef(accessKey);
  const pollJitterRef = useRef(0.82 + Math.random() * 0.3);
  const [threadState, setThreadState] = useState<{
    projectId: string;
    thread?: ProjectFeedbackThread;
  }>(() => ({projectId, thread: seedThread}));
  const thread =
    threadState.projectId === projectId ? threadState.thread : seedThread;
  const setThread = useCallback(
    (next?: ProjectFeedbackThread) => setThreadState({projectId, thread: next}),
    [projectId],
  );
  const [hydrating, setHydrating] = useState(false);
  const [readFailure, setReadFailure] = useState<{message: string} | null>(
    null,
  );
  const readFailureRef = useRef<typeof readFailure>(null);
  const readError = readFailure?.message || '';
  const setReadError = useCallback((message: string) => {
    const failure = message ? {message} : null;
    readFailureRef.current = failure;
    setReadFailure(failure);
  }, []);
  const [readRetrying, setReadRetrying] = useState(false);
  const [retryRevision, setRetryRevision] = useState(0);
  const handledRetryRef = useRef(0);
  const retryFlightRef = useRef(false);
  const liveReadContextRef = useRef({active, appIsActive});
  liveReadContextRef.current = {active, appIsActive};
  activeThreadIdRef.current = thread?.id || null;
  const threadHydrating =
    hydrating ||
    (active &&
      appIsActive &&
      ['ready', 'failed'].includes(reportStatus) &&
      Boolean(thread) &&
      (((thread?.messages.length || 0) === 0 &&
        hydratedThreadRef.current !== thread?.id) ||
        hydratedAccessRef.current !== accessKey) &&
      !readError);
  const pending = projectFeedbackThreadIsPending(thread?.messages || []);
  useEffect(() => {
    setThreadState(current => {
      if (
        seedThread?.transcriptIncluded === false &&
        current.projectId === projectId &&
        current.thread?.id === seedThread.id
      ) {
        // Course maps intentionally omit messages, quota and attachment limits.
        // Keep the full read/send result, but apply the summary's real access
        // verdict so a revoked permission cannot leave the composer enabled.
        return {
          projectId,
          thread: {
            ...current.thread,
            canReply: seedThread.canReply,
            feedbackLevel: seedThread.feedbackLevel,
            status: seedThread.status,
          },
        };
      }
      return {projectId, thread: seedThread};
    });
  }, [projectId, seedThread]);

  useEffect(() => {
    generationRef.current += 1;
    hydratedThreadRef.current = null;
    setHydrating(false);
    setReadError('');
    setReadRetrying(false);
    retryFlightRef.current = false;
    return () => {
      generationRef.current += 1;
      readFailureRef.current = null;
    };
  }, [projectId, setReadError, thread?.id]);

  const refreshRead = useCallback(() => {
    if (
      retryFlightRef.current ||
      !liveReadContextRef.current.active ||
      !liveReadContextRef.current.appIsActive ||
      activeProjectIdRef.current !== projectId ||
      activeThreadIdRef.current !== thread?.id ||
      !thread?.id
    ) {
      return;
    }
    // Both explicit recovery and a completed upgrade enter the same GET owner.
    // No second polling loop, report generation or message send is introduced.
    readFailureRef.current = null;
    retryFlightRef.current = true;
    setReadRetrying(true);
    setRetryRevision(value => value + 1);
  }, [projectId, thread?.id]);

  const retryRead = useCallback(() => {
    if (!readFailure || readFailureRef.current !== readFailure) return;
    refreshRead();
  }, [readFailure, refreshRead]);

  // One GET owner handles hydration, access refresh and pending replies. A
  // manual retry resumes this read pipeline, never report generation or send.
  useEffect(() => {
    const threadId = thread?.id;
    const manualRetry = retryRevision !== handledRetryRef.current;
    handledRetryRef.current = retryRevision;
    if (
      !active ||
      !appIsActive ||
      !threadId ||
      !['ready', 'failed'].includes(reportStatus)
    ) {
      // A payment or another project may consume/change this course allowance
      // while away. The course summary cannot refresh quota on return.
      hydratedAccessRef.current = '';
      readFailureRef.current = null;
      retryFlightRef.current = false;
      setReadRetrying(false);
      setHydrating(false);
      return;
    }
    const needsHydration =
      ((thread?.messages.length || 0) === 0 &&
        hydratedThreadRef.current !== threadId) ||
      hydratedAccessRef.current !== accessKey;
    if (
      !manualRetry &&
      !needsHydration &&
      !(pending && reportStatus === 'ready')
    )
      return;
    const generation = generationRef.current;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let attempts = 0;
    let loadingReport = needsHydration;
    let manualReadPending = manualRetry;
    if (manualRetry) retryFlightRef.current = true;
    else {
      setReadError('');
    }
    setHydrating(needsHydration || manualRetry);
    const ownsThread = () =>
      !cancelled &&
      generationRef.current === generation &&
      activeProjectIdRef.current === projectId &&
      activeThreadIdRef.current === threadId;
    const schedule = () => {
      const delay = loadingReport
        ? 1200 * attempts
        : Math.round(
            Math.min(10000, 1800 * Math.pow(1.35, attempts)) *
              pollJitterRef.current,
          );
      timer = setTimeout(() => void load(), delay);
    };
    const finishRetry = () => {
      retryFlightRef.current = false;
      setReadRetrying(false);
    };
    const load = async () => {
      const explicitAttempt = manualReadPending;
      manualReadPending = false;
      attempts += 1;
      try {
        const next = await loadProjectFeedbackThread(projectId, threadId);
        if (!ownsThread()) return;
        if (next?.id === threadId && next.messages.length > 0) {
          hydratedThreadRef.current = threadId;
          hydratedAccessRef.current = accessKey;
          setThread(next);
          setReadError('');
          setHydrating(false);
          finishRetry();
          loadingReport = false;
          if (
            reportStatus !== 'ready' ||
            !projectFeedbackThreadIsPending(next.messages)
          )
            return;
          if (explicitAttempt) attempts = 0;
          if (attempts < 30) {
            schedule();
            return;
          }
        }
      } catch (caught) {
        if (!ownsThread()) return;
        if (
          caught instanceof Error &&
          caught.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
        ) {
          setReadError('');
          setHydrating(false);
          finishRetry();
          return;
        }
      }
      if (!ownsThread()) return;
      // Explicit retries acknowledge a failed GET immediately. Automatic
      // hydration/polling retains its existing bounded backoff budget.
      if (!explicitAttempt && attempts < (loadingReport ? 3 : 30)) {
        schedule();
        return;
      }
      setHydrating(false);
      finishRetry();
      setReadError(loadingReport ? 'تعذّر تحميل التقرير' : 'تعذّر تحديث الرد');
    };
    if (needsHydration || manualRetry) void load();
    else schedule();
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
      retryFlightRef.current = false;
    };
  }, [
    accessKey,
    active,
    appIsActive,
    pending,
    projectId,
    reportStatus,
    retryRevision,
    setReadError,
    setThread,
    thread?.id,
    thread?.messages.length,
  ]);

  return {
    thread,
    setThread,
    readError,
    readRetrying,
    retryRead,
    refreshRead,
    hydrating: threadHydrating,
    pending,
  };
};
