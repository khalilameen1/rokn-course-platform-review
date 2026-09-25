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
  const [error, setError] = useState('');
  activeThreadIdRef.current = thread?.id || null;
  const threadHydrating =
    hydrating ||
    (['ready', 'failed'].includes(reportStatus) &&
      Boolean(thread) &&
      (thread?.messages.length || 0) === 0 &&
      hydratedThreadRef.current !== thread?.id &&
      !error);
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
    setError('');
    return () => {
      generationRef.current += 1;
    };
  }, [projectId, thread?.id]);

  useEffect(() => {
    const threadId = thread?.id;
    if (
      !active ||
      !appIsActive ||
      !threadId ||
      (((thread?.messages.length || 0) > 0 ||
        hydratedThreadRef.current === threadId) &&
        hydratedAccessRef.current === accessKey) ||
      !['ready', 'failed'].includes(reportStatus)
    ) {
      return;
    }
    const generation = generationRef.current;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let attempts = 0;
    setHydrating(true);
    const ownsThread = () =>
      !cancelled &&
      generationRef.current === generation &&
      activeProjectIdRef.current === projectId &&
      activeThreadIdRef.current === threadId;
    const load = async () => {
      attempts += 1;
      try {
        const next = await loadProjectFeedbackThread(projectId, threadId);
        if (!ownsThread()) return;
        if (next) {
          hydratedThreadRef.current = threadId;
          hydratedAccessRef.current = accessKey;
          setThread(next);
          setError('');
          setHydrating(false);
          return;
        }
      } catch {}
      if (!ownsThread()) return;
      if (attempts < 3) {
        timer = setTimeout(() => void load(), 1200 * attempts);
        return;
      }
      hydratedThreadRef.current = null;
      setHydrating(false);
      setError('تعذّر تحميل التقرير\nحاول فتح المشروع مرة أخرى');
    };
    void load();
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [
    accessKey,
    active,
    appIsActive,
    projectId,
    reportStatus,
    setThread,
    thread?.id,
    thread?.messages.length,
  ]);

  useEffect(() => {
    const threadId = thread?.id;
    if (
      !active ||
      !appIsActive ||
      !threadId ||
      !pending ||
      reportStatus !== 'ready'
    ) {
      return;
    }
    const generation = generationRef.current;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let attempts = 0;
    const ownsThread = () =>
      !cancelled &&
      generationRef.current === generation &&
      activeProjectIdRef.current === projectId &&
      activeThreadIdRef.current === threadId;
    const schedule = () => {
      const delay = Math.min(10000, 1800 * Math.pow(1.35, attempts));
      timer = setTimeout(
        () => void refresh(),
        Math.round(delay * pollJitterRef.current),
      );
    };
    const refresh = async () => {
      attempts += 1;
      try {
        const next = await loadProjectFeedbackThread(projectId, threadId);
        if (!ownsThread()) return;
        if (next) {
          setThread(next);
          setError('');
          if (!projectFeedbackThreadIsPending(next.messages)) return;
        }
      } catch {}
      if (!ownsThread()) return;
      if (attempts < 30) {
        schedule();
      } else {
        setError('تأخر الرد\nافتح المشروع مرة أخرى لتحديثه');
      }
    };
    schedule();
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [
    active,
    appIsActive,
    pending,
    projectId,
    reportStatus,
    setThread,
    thread?.id,
  ]);

  return {
    thread,
    setThread,
    error,
    setError,
    hydrating: threadHydrating,
    pending,
  };
};
