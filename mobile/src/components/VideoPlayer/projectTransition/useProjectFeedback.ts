import {useCallback, useEffect, useRef, useState} from 'react';
import * as DocumentPicker from 'expo-document-picker';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {removeLearnerDraftFile} from '../../../services/learnerDraftFiles';
import {showMediaPickerFailure} from '../../../services/mediaPickerErrors';
import {
  cacheProjectFeedbackFile,
  clearProjectFeedbackDraft,
  loadProjectFeedbackDraft,
  saveProjectFeedbackDraft,
} from '../../../services/projectFeedbackDraft';
import {learnerErrorMessage} from '../../../utils/errorPayload';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {cleanUnicodeText, truncateGraphemes} from '../../../utils/unicodeText';
import {
  loadProjectFeedbackThread,
  sendProjectFeedbackMessage,
  uploadProjectFeedbackAttachment,
} from '../courseLearningApi';
import {projectFeedbackThreadIsPending} from '../projectFeedback/policy';
import type {
  ChatAttachmentDraft,
  ProjectFeedbackMessage,
  ProjectFeedbackThread,
  ProjectReportStatus,
} from '../types';

type FeedbackLevel = 'pass_only' | 'report' | 'enhanced';

export const useProjectFeedback = ({
  active,
  appIsActive,
  projectId,
  seedThread,
  feedbackLevel,
  replyEnabled,
  reportStatus,
}: {
  active: boolean;
  appIsActive: boolean;
  projectId: string;
  seedThread?: ProjectFeedbackThread;
  feedbackLevel: FeedbackLevel;
  replyEnabled: boolean;
  reportStatus: ProjectReportStatus;
}) => {
  const activeProjectIdRef = useRef(projectId);
  activeProjectIdRef.current = projectId;
  const activeThreadIdRef = useRef<string | null>(seedThread?.id || null);
  const generationRef = useRef(0);
  const requestRef = useRef<{fingerprint: string; id: string} | null>(null);
  const sendFlightRef = useRef<symbol | null>(null);
  const pickerFlightRef = useRef<symbol | null>(null);
  const hydratedThreadRef = useRef<string | null>(null);
  const pollJitterRef = useRef(0.82 + Math.random() * 0.3);
  const draftSnapshotRef = useRef({
    text: '',
    attachments: [] as ChatAttachmentDraft[],
  });
  const draftReadyRef = useRef(false);
  const draftBoundaryRef = useRef<AccountSessionBoundary | null>(null);

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
  const [draft, setDraft] = useState('');
  const [attachments, setAttachments] = useState<ChatAttachmentDraft[]>([]);
  const [draftReady, setDraftReady] = useState(false);
  const [hydrating, setHydrating] = useState(false);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');

  activeThreadIdRef.current = thread?.id || null;
  draftSnapshotRef.current = {text: draft, attachments};
  draftReadyRef.current = draftReady;

  const normalizedDraft = cleanUnicodeText(draft);
  const threadHydrating =
    hydrating ||
    (reportStatus === 'ready' &&
      Boolean(thread) &&
      (thread?.messages.length || 0) === 0 &&
      hydratedThreadRef.current !== thread?.id &&
      !error);
  const canReply =
    !threadHydrating &&
    feedbackLevel === 'enhanced' &&
    replyEnabled &&
    thread?.canReply === true;
  const pending = projectFeedbackThreadIsPending(thread?.messages || []);

  useEffect(() => {
    setThread(seedThread);
  }, [seedThread, setThread]);

  useEffect(() => {
    generationRef.current += 1;
    hydratedThreadRef.current = null;
    requestRef.current = null;
    sendFlightRef.current = null;
    pickerFlightRef.current = null;
    setSending(false);
    setError('');
    setDraft('');
    setAttachments([]);
    setDraftReady(false);
    draftBoundaryRef.current = null;
    return () => {
      generationRef.current += 1;
      pickerFlightRef.current = null;
    };
  }, [projectId, thread?.id]);

  useEffect(() => {
    const threadId = thread?.id;
    if (
      !active ||
      !appIsActive ||
      !threadId ||
      (thread?.messages.length || 0) > 0 ||
      hydratedThreadRef.current === threadId ||
      reportStatus !== 'ready'
    ) {
      return;
    }
    const generation = generationRef.current;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let attempts = 0;
    hydratedThreadRef.current = threadId;
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

  useEffect(() => {
    const threadId = thread?.id;
    if (!threadId) return;
    const generation = generationRef.current;
    let cancelled = false;
    setDraftReady(false);
    void captureAccountSessionBoundary()
      .then(boundary => {
        if (cancelled || generationRef.current !== generation) return null;
        draftBoundaryRef.current = boundary;
        return loadProjectFeedbackDraft(threadId, boundary);
      })
      .then(saved => {
        if (cancelled || generationRef.current !== generation || !saved) return;
        setDraft(saved.text);
        setAttachments(saved.attachments);
        if (saved.requestId && saved.fingerprint) {
          requestRef.current = {
            id: saved.requestId,
            fingerprint: saved.fingerprint,
          };
        }
      })
      .catch(() => undefined)
      .finally(() => {
        if (!cancelled && generationRef.current === generation)
          setDraftReady(true);
      });
    return () => {
      cancelled = true;
    };
  }, [thread?.id]);

  useEffect(
    () => () => {
      const threadId = thread?.id;
      const boundary = draftBoundaryRef.current;
      if (!threadId || !boundary || !draftReadyRef.current) return;
      void saveProjectFeedbackDraft(
        threadId,
        {
          ...draftSnapshotRef.current,
          requestId: requestRef.current?.id,
          fingerprint: requestRef.current?.fingerprint,
          updatedAt: Date.now(),
        },
        boundary,
      ).catch(() => undefined);
    },
    [thread?.id],
  );

  useEffect(() => {
    const threadId = thread?.id;
    const boundary = draftBoundaryRef.current;
    if (!threadId || !boundary || !draftReady) return;
    const timer = setTimeout(() => {
      void saveProjectFeedbackDraft(
        threadId,
        {
          text: draft,
          attachments,
          requestId: requestRef.current?.id,
          fingerprint: requestRef.current?.fingerprint,
          updatedAt: Date.now(),
        },
        boundary,
      ).catch(() => undefined);
    }, 250);
    return () => clearTimeout(timer);
  }, [attachments, draft, draftReady, thread?.id]);

  useEffect(() => {
    const threadId = thread?.id;
    const requestId = requestRef.current?.id;
    if (!threadId || !requestId || !draftReady) return;
    const serverOwnsRequest = thread.messages.some(
      message =>
        message.role === 'user' &&
        message.clientRequestId === requestId &&
        !['failed', 'cancelled'].includes(message.status),
    );
    if (!serverOwnsRequest) return;
    const localFiles = attachments;
    const boundary = draftBoundaryRef.current;
    if (!boundary) return;
    requestRef.current = null;
    setDraft('');
    setAttachments([]);
    void clearProjectFeedbackDraft(threadId, localFiles, boundary).catch(
      () => undefined,
    );
  }, [attachments, draftReady, thread]);

  useEffect(() => {
    const threadId = thread?.id;
    const boundary = draftBoundaryRef.current;
    if (appIsActive || !threadId || !boundary || !draftReady) return;
    void saveProjectFeedbackDraft(
      threadId,
      {
        text: draft,
        attachments,
        requestId: requestRef.current?.id,
        fingerprint: requestRef.current?.fingerprint,
        updatedAt: Date.now(),
      },
      boundary,
    ).catch(() => undefined);
  }, [appIsActive, attachments, draft, draftReady, thread?.id]);

  const send = useCallback(
    async ({
      text = draft,
      clientRequestId,
      forceNewRequest = false,
      files = attachments,
    }: {
      text?: string;
      clientRequestId?: string;
      forceNewRequest?: boolean;
      files?: ChatAttachmentDraft[];
    } = {}) => {
      const value = cleanUnicodeText(text);
      const fingerprint = [
        value,
        ...files.map(
          file =>
            `${file.serverId || file.uploadId}:${file.name}:${file.size || 0}`,
        ),
      ].join('|');
      if (
        !canReply ||
        (!value && files.length === 0) ||
        sending ||
        sendFlightRef.current ||
        pickerFlightRef.current ||
        !thread
      ) {
        return;
      }
      const flight = Symbol('project-feedback-send');
      const generation = generationRef.current;
      const threadId = thread.id;
      const ownsContext = () =>
        sendFlightRef.current === flight &&
        generationRef.current === generation &&
        activeProjectIdRef.current === projectId &&
        activeThreadIdRef.current === threadId;
      sendFlightRef.current = flight;
      setSending(true);
      setError('');
      const requestId =
        clientRequestId ||
        (!forceNewRequest && requestRef.current?.fingerprint === fingerprint
          ? requestRef.current.id
          : secureRandomUuid());
      requestRef.current = {fingerprint, id: requestId};
      try {
        const boundary = await captureAccountSessionBoundary();
        const draftBoundary = draftBoundaryRef.current;
        if (
          !draftBoundary ||
          draftBoundary.scope !== boundary.scope ||
          draftBoundary.epoch !== boundary.epoch
        ) {
          throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
        }
        const uploaded = await Promise.all(
          files.map(async file => ({
            ...file,
            serverId:
              file.serverId ||
              (await uploadProjectFeedbackAttachment(threadId, file)),
          })),
        );
        assertAccountSessionBoundary(boundary);
        if (!ownsContext()) return;
        const durableFingerprint = [
          value,
          ...uploaded.map(
            file =>
              `${file.serverId || file.uploadId}:${file.name}:${
                file.size || 0
              }`,
          ),
        ].join('|');
        await saveProjectFeedbackDraft(
          threadId,
          {
            text: value,
            attachments: uploaded,
            requestId,
            fingerprint: durableFingerprint,
            updatedAt: Date.now(),
          },
          boundary,
        );
        assertAccountSessionBoundary(boundary);
        if (!ownsContext()) return;
        setAttachments(uploaded);
        requestRef.current = {id: requestId, fingerprint: durableFingerprint};
        const next = await sendProjectFeedbackMessage(
          threadId,
          value,
          requestId,
          uploaded.map(file => file.serverId!).filter(Boolean),
        );
        assertAccountSessionBoundary(boundary);
        if (!ownsContext()) return;
        void clearProjectFeedbackDraft(threadId, uploaded, boundary).catch(
          () => undefined,
        );
        setThread(next);
        setDraft('');
        setAttachments([]);
        requestRef.current = null;
      } catch (caught: unknown) {
        if (
          !ownsContext() ||
          (caught instanceof Error &&
            caught.message === 'ACCOUNT_CHANGED_DURING_REQUEST')
        ) {
          return;
        }
        setError(
          learnerErrorMessage(caught, 'لم تُرسل الرسالة\nحاول مرة أخرى'),
        );
      } finally {
        if (sendFlightRef.current === flight) {
          sendFlightRef.current = null;
          if (
            generationRef.current === generation &&
            activeProjectIdRef.current === projectId &&
            activeThreadIdRef.current === threadId
          ) {
            setSending(false);
          }
        }
      }
    },
    [attachments, canReply, draft, projectId, sending, setThread, thread],
  );

  const pickAttachments = useCallback(async () => {
    if (
      !canReply ||
      !thread?.attachmentsEnabled ||
      pickerFlightRef.current ||
      sendFlightRef.current
    ) {
      return;
    }
    const threadId = thread.id;
    const generation = generationRef.current;
    const flight = Symbol('project-feedback-picker');
    const ownsContext = () =>
      generationRef.current === generation &&
      activeProjectIdRef.current === projectId &&
      activeThreadIdRef.current === threadId;
    const ownsPicker = () =>
      pickerFlightRef.current === flight && ownsContext();
    pickerFlightRef.current = flight;
    const additions: ChatAttachmentDraft[] = [];
    try {
      const boundary = await captureAccountSessionBoundary();
      const draftBoundary = draftBoundaryRef.current;
      if (
        !draftBoundary ||
        draftBoundary.scope !== boundary.scope ||
        draftBoundary.epoch !== boundary.epoch
      ) {
        throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
      }
      const result = await DocumentPicker.getDocumentAsync({
        type: [
          'image/jpeg',
          'image/png',
          'image/webp',
          'application/pdf',
          'text/plain',
          'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
          'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
        multiple: true,
        copyToCacheDirectory: true,
      });
      assertAccountSessionBoundary(boundary);
      if (result.canceled || !ownsPicker()) return;
      const maximum = Math.max(0, thread.attachmentMaxFiles || 0);
      const remaining = Math.max(0, maximum - attachments.length);
      for (const asset of result.assets.slice(0, remaining)) {
        additions.push(
          await cacheProjectFeedbackFile(
            {
              uri: asset.uri,
              name: asset.name,
              type: asset.mimeType || 'application/octet-stream',
              size: asset.size,
              uploadId: secureRandomUuid(),
            },
            boundary,
          ),
        );
        assertAccountSessionBoundary(boundary);
        if (!ownsPicker()) {
          await Promise.all(additions.map(removeLearnerDraftFile));
          return;
        }
      }
      setAttachments(current => {
        if (!ownsContext()) {
          void Promise.all(additions.map(removeLearnerDraftFile));
          return current;
        }
        const kept = [...current, ...additions].slice(0, maximum);
        const keptIds = new Set(kept.map(file => file.uploadId));
        void Promise.all(
          additions
            .filter(file => !keptIds.has(file.uploadId))
            .map(removeLearnerDraftFile),
        );
        return kept;
      });
    } catch (caught: unknown) {
      await Promise.all(additions.map(removeLearnerDraftFile));
      if (!ownsPicker()) return;
      if (
        caught instanceof Error &&
        caught.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      ) {
        return;
      }
      showMediaPickerFailure(
        caught instanceof Error &&
          caught.message === 'LEARNER_DRAFT_STORAGE_FULL'
          ? caught.message
          : 'document_picker_failed',
      );
    } finally {
      if (pickerFlightRef.current === flight) pickerFlightRef.current = null;
    }
  }, [attachments.length, canReply, projectId, thread]);

  const retryMessage = useCallback(
    (message: ProjectFeedbackMessage) =>
      send({
        text: message.text || '',
        forceNewRequest: true,
        files: message.attachments || [],
      }),
    [send],
  );

  const removeAttachment = useCallback((file: ChatAttachmentDraft) => {
    if (sendFlightRef.current) return;
    setAttachments(current =>
      current.filter(item => item.uploadId !== file.uploadId),
    );
    if (!file.serverId) void removeLearnerDraftFile(file);
  }, []);

  const changeDraft = useCallback((value: string) => {
    if (!sendFlightRef.current) setDraft(truncateGraphemes(value, 2000));
  }, []);

  return {
    attachments,
    canReply,
    changeDraft,
    draft,
    error,
    hydrating: threadHydrating,
    normalizedDraft,
    pending,
    pickAttachments,
    removeAttachment,
    retryMessage,
    send,
    sending,
    thread,
  };
};
