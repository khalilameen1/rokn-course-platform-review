import {useCallback, useEffect, useRef, useState} from 'react';
import {requestAiConsent} from '../../../services/aiConsent';
import * as DocumentPicker from 'expo-document-picker';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../../constants/helpers';
import {removeLearnerDraftFile} from '../../../services/learnerDraftFiles';
import {showMediaPickerFailure} from '../../../services/mediaPickerErrors';
import {cacheProjectFeedbackFile} from '../../../services/projectFeedbackDraft';
import {errorStatus, learnerErrorMessage} from '../../../utils/errorPayload';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {cleanUnicodeText, truncateGraphemes} from '../../../utils/unicodeText';
import {
  loadProjectFeedbackThread,
  sendProjectFeedbackMessage,
  uploadProjectFeedbackAttachment,
} from '../courseLearningApi';
import type {
  ChatAttachmentDraft,
  ProjectFeedbackMessage,
  ProjectFeedbackThread,
  ProjectReportStatus,
} from '../types';

import {useProjectFeedbackThread} from './useProjectFeedbackThread';
import {useProjectFeedbackDraftEditor} from './useProjectFeedbackDraftEditor';

const draftFingerprint = (text: string, files: ChatAttachmentDraft[]) =>
  [
    text,
    ...files.map(
      file =>
        `${file.serverId || file.uploadId}:${file.name}:${file.size || 0}`,
    ),
  ].join('|');

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
  const sendFlightRef = useRef<symbol | null>(null);
  const pickerFlightRef = useRef<symbol | null>(null);
  const {
    thread,
    setThread,
    error,
    setError,
    hydrating: threadHydrating,
    pending,
  } = useProjectFeedbackThread({
    projectId,
    seedThread,
    active,
    appIsActive,
    feedbackLevel,
    replyEnabled,
    reportStatus,
  });
  const {
    session: draftSession,
    draft,
    setDraft,
    attachments,
    setAttachments,
    ready: draftReady,
    saveError: draftSaveError,
    restoreError: draftRestoreError,
    retryRestore: retryDraftRestore,
    retrySave: retryDraftSave,
  } = useProjectFeedbackDraftEditor({
    projectId,
    threadId: thread?.id,
    active,
    appIsActive,
  });
  const [sending, setSending] = useState(false);

  activeThreadIdRef.current = thread?.id || null;

  const normalizedDraft = cleanUnicodeText(draft);
  const canReply =
    draftReady &&
    !threadHydrating &&
    reportStatus === 'ready' &&
    feedbackLevel === 'enhanced' &&
    replyEnabled &&
    thread?.canReply === true;
  useEffect(() => {
    generationRef.current += 1;
    sendFlightRef.current = null;
    pickerFlightRef.current = null;
    setSending(false);
    return () => {
      generationRef.current += 1;
      pickerFlightRef.current = null;
    };
  }, [projectId, thread?.id]);

  useEffect(() => {
    const requestId = draftSession.snapshot.requestId;
    if (!thread || !requestId || !draftReady) return;
    const serverOwnsRequest = thread.messages.some(
      message =>
        message.role === 'user' &&
        message.clientRequestId === requestId &&
        !['failed', 'cancelled'].includes(message.status),
    );
    if (!serverOwnsRequest) return;
    try {
      draftSession.consume(requestId, attachments);
    } catch {
      // An old account's receipt cannot mutate the current editor.
    }
  }, [attachments, draft, draftReady, draftSession, thread]);

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
      const fingerprint = draftFingerprint(value, files);
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
      const savedRequest = draftSession.snapshot;
      const requestId =
        clientRequestId ||
        (!forceNewRequest &&
        savedRequest.fingerprint === fingerprint &&
        savedRequest.requestId
          ? savedRequest.requestId
          : secureRandomUuid());
      try {
        const boundary = await captureAccountSessionBoundary();
        const draftBoundary = draftSession.boundary;
        if (
          !draftBoundary ||
          draftBoundary.scope !== boundary.scope ||
          draftBoundary.epoch !== boundary.epoch
        ) {
          throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
        }
        if (!(await requestAiConsent(boundary))) return;
        assertAccountSessionBoundary(boundary);
        if (!ownsContext()) return;
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
        await draftSession.stage({
          text: value,
          attachments: uploaded,
          requestId,
          fingerprint: draftFingerprint(value, uploaded),
        });
        assertAccountSessionBoundary(boundary);
        if (!ownsContext()) return;
        let next: ProjectFeedbackThread;
        try {
          next = await sendProjectFeedbackMessage(
            threadId,
            value,
            requestId,
            uploaded.map(file => file.serverId!).filter(Boolean),
          );
        } catch (sendError) {
          assertAccountSessionBoundary(boundary);
          if (!ownsContext()) return;
          const status = errorStatus(sendError);
          if (status >= 400 && status < 500 && status !== 408) throw sendError;

          // A lost acknowledgement is not proof that the message was rejected.
          // Read the existing thread once; never start a second paid request.
          const recovered = await loadProjectFeedbackThread(
            projectId,
            threadId,
          ).catch(() => null);
          assertAccountSessionBoundary(boundary);
          if (!ownsContext()) return;
          if (
            recovered?.id !== threadId ||
            !recovered.messages.some(
              message =>
                message.role === 'user' &&
                message.clientRequestId === requestId,
            )
          ) {
            // The durable draft retains this same request ID for explicit retry.
            setError('تعذّر تأكيد إرسال الرسالة\nمسودتك محفوظة حاول مرة أخرى');
            return;
          }
          next = recovered;
        }
        assertAccountSessionBoundary(boundary);
        if (!ownsContext()) return;
        draftSession.consume(requestId, uploaded);
        setThread(next);
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
    [
      attachments,
      canReply,
      draft,
      draftSession,
      projectId,
      sending,
      setError,
      setThread,
      thread,
    ],
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
      const draftBoundary = draftSession.boundary;
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
  }, [
    attachments.length,
    canReply,
    draftSession,
    projectId,
    setAttachments,
    thread,
  ]);

  const retryMessage = useCallback(
    (message: ProjectFeedbackMessage) =>
      send({
        text: message.text || '',
        forceNewRequest: true,
        files: message.attachments || [],
      }),
    [send],
  );

  const removeAttachment = useCallback(
    (file: ChatAttachmentDraft) => {
      if (!draftSession.ready || sendFlightRef.current) return;
      try {
        draftSession.assertReady();
      } catch {
        return;
      }
      setAttachments(current =>
        current.filter(item => item.uploadId !== file.uploadId),
      );
      if (!file.serverId) void removeLearnerDraftFile(file);
    },
    [draftSession, setAttachments],
  );

  const changeDraft = useCallback(
    (value: string) => {
      if (draftSession.ready && !sendFlightRef.current)
        setDraft(truncateGraphemes(value, 2000));
    },
    [draftSession, setDraft],
  );

  return {
    attachments,
    canReply,
    changeDraft,
    draft,
    draftRestoreError,
    retryDraftRestore,
    draftSaveError,
    retryDraftSave,
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
