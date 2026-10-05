import {useCallback, useEffect, useRef} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';
import * as DocumentPicker from 'expo-document-picker';
import type {ChatAttachmentDraft} from '../types';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {
  cacheLearnerDraftFile,
  removeLearnerDraftFile,
} from '../../../services/learnerDraftFiles';
import {showMediaPickerFailure} from '../../../services/mediaPickerErrors';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {reportClientError} from '../../../services/operationalTelemetry';

const CHAT_ATTACHMENT_MIME_TYPES = [
  'image/jpeg',
  'image/png',
  'image/webp',
  'application/pdf',
  'text/plain',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];

const discardPickedFiles = async (files: ChatAttachmentDraft[]) => {
  await Promise.all(files.map(removeLearnerDraftFile)).catch((error: unknown) => {
    void reportClientError(
      error instanceof Error ? error : new Error('CHAT_DRAFT_CLEANUP_FAILED'),
      {source: 'course_chat_attachment_cleanup'},
    );
  });
};

export const useCourseChatAttachments = ({
  appIsActive,
  attachmentsRef,
  conversationScope,
  enabled,
  isSendInFlight,
  limit,
  sending,
  setAttachments,
  visible,
}: {
  appIsActive: boolean;
  attachmentsRef: MutableRefObject<ChatAttachmentDraft[]>;
  conversationScope: string;
  enabled: boolean;
  isSendInFlight: () => boolean;
  limit: number;
  sending: boolean;
  setAttachments: Dispatch<SetStateAction<ChatAttachmentDraft[]>>;
  visible: boolean;
}) => {
  const available = visible && enabled && limit > 0;
  const visitRef = useRef({conversationScope, available, limit});
  if (
    visitRef.current.conversationScope !== conversationScope ||
    visitRef.current.available !== available ||
    visitRef.current.limit !== limit
  ) visitRef.current = {conversationScope, available, limit};
  const visit = visitRef.current;
  // Native document selection temporarily backgrounds the app. Retire only
  // pre-launch preparation on a foreground change, not the native selection.
  const preparationRef = useRef({visit, appIsActive});
  if (
    preparationRef.current.visit !== visit ||
    preparationRef.current.appIsActive !== appIsActive
  ) preparationRef.current = {visit, appIsActive};
  const preparation = preparationRef.current;
  const mountedRef = useRef(false);
  const pickerFlightRef = useRef<{
    visit: typeof visit;
    preparation: typeof preparation;
    nativeStarted: boolean;
  } | null>(null);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      if (pickerFlightRef.current?.visit === visit)
        pickerFlightRef.current = null;
    };
  }, [visit]);
  useEffect(() => {
    const flight = pickerFlightRef.current;
    if (flight && !flight.nativeStarted && flight.preparation !== preparation)
      pickerFlightRef.current = null;
  }, [preparation]);

  const pickAttachments = useCallback(async () => {
    if (
      !mountedRef.current ||
      visitRef.current !== visit ||
      preparationRef.current !== preparation ||
      !preparation.appIsActive ||
      !visit.available ||
      attachmentsRef.current.length >= limit ||
      pickerFlightRef.current ||
      sending ||
      isSendInFlight()
    ) {
      return;
    }

    const flight = {visit, preparation, nativeStarted: false};
    pickerFlightRef.current = flight;
    let boundary: AccountSessionBoundary | undefined;
    const ownsPicker = () => {
      if (
        !mountedRef.current ||
        visitRef.current !== visit ||
        pickerFlightRef.current !== flight
      ) return false;
      try {
        if (boundary) assertAccountSessionBoundary(boundary);
        return true;
      } catch {
        return false;
      }
    };
    const selected: ChatAttachmentDraft[] = [];
    let retainedByComposer = false;

    try {
      boundary = await captureAccountSessionBoundary();
      assertAccountSessionBoundary(boundary);
      if (
        !ownsPicker() ||
        preparationRef.current !== preparation ||
        isSendInFlight()
      ) return;
      flight.nativeStarted = true;
      const result = await DocumentPicker.getDocumentAsync({
        type: CHAT_ATTACHMENT_MIME_TYPES,
        multiple: true,
        copyToCacheDirectory: true,
      });
      assertAccountSessionBoundary(boundary);
      if (result.canceled || !ownsPicker()) return;

      const remaining = Math.max(0, limit - attachmentsRef.current.length);
      for (const asset of result.assets.slice(0, remaining)) {
        const cached = await cacheLearnerDraftFile(
          'course_chat',
          {
            uri: asset.uri,
            fileName: asset.name,
            type: asset.mimeType || 'application/octet-stream',
            size: asset.size,
          },
          8 * 1024 * 1024,
          boundary,
        );
        selected.push({
          uri: cached.uri,
          name: cached.fileName || asset.name,
          type: cached.type || asset.mimeType || 'application/octet-stream',
          size: cached.size,
          uploadId: secureRandomUuid(),
        });
        // Track the owned copy before checking for a departed account/visit,
        // so a result made obsolete at this await is still cleaned up.
        assertAccountSessionBoundary(boundary);
        if (!ownsPicker()) return;
      }

      if (!ownsPicker()) return;
      // Once native selection started, a recovery of an older sent turn may
      // continue independently. It uses its own outbox, not this next-question
      // composer. Controller send/retry remains gated until copying settles.
      retainedByComposer = true;
      setAttachments(current => {
        const kept = [...current, ...selected].slice(0, limit);
        const keptIds = new Set(kept.map(file => file.uploadId));
        void discardPickedFiles(selected.filter(file => !keptIds.has(file.uploadId)));
        return kept;
      });
    } catch (error: unknown) {
      if (
        preparationRef.current.appIsActive &&
        ownsPicker() &&
        !(
          error instanceof Error &&
          error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
        )
      ) {
        showMediaPickerFailure(
          error instanceof Error &&
            error.message === 'LEARNER_DRAFT_STORAGE_FULL'
            ? error.message
            : 'document_picker_failed',
        );
      }
    } finally {
      try {
        if (!retainedByComposer)
          await discardPickedFiles(selected);
      } finally {
        // An obsolete operation must never release the new visit's picker.
        if (pickerFlightRef.current === flight) pickerFlightRef.current = null;
      }
    }
  }, [
    attachmentsRef,
    isSendInFlight,
    limit,
    sending,
    setAttachments,
    visit,
    preparation,
  ]);

  return {
    pickAttachments,
    pickerIsActive: useCallback(() => Boolean(pickerFlightRef.current), []),
  };
};
