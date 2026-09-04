import {useCallback, useEffect, useRef} from 'react';
import type {Dispatch, SetStateAction} from 'react';
import * as DocumentPicker from 'expo-document-picker';
import type {ChatAttachmentDraft} from '../types';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../../constants/helpers';
import {
  cacheLearnerDraftFile,
  removeLearnerDraftFile,
} from '../../../services/learnerDraftFiles';
import {showMediaPickerFailure} from '../../../services/mediaPickerErrors';
import {secureRandomUuid} from '../../../utils/secureRandom';

const CHAT_ATTACHMENT_MIME_TYPES = [
  'image/jpeg',
  'image/png',
  'image/webp',
  'application/pdf',
  'text/plain',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];

export const useCourseChatAttachments = ({
  attachments,
  courseId,
  enabled,
  isSendInFlight,
  limit,
  sending,
  setAttachments,
  visible,
}: {
  attachments: ChatAttachmentDraft[];
  courseId: string;
  enabled: boolean;
  isSendInFlight: () => boolean;
  limit: number;
  sending: boolean;
  setAttachments: Dispatch<SetStateAction<ChatAttachmentDraft[]>>;
  visible: boolean;
}) => {
  const pickerFlightRef = useRef(false);
  const pickerGenerationRef = useRef(0);
  const activeCourseRef = useRef(courseId);
  const visibleRef = useRef(visible);
  activeCourseRef.current = courseId;
  visibleRef.current = visible;

  useEffect(() => {
    pickerGenerationRef.current += 1;
    pickerFlightRef.current = false;
    return () => {
      pickerGenerationRef.current += 1;
    };
  }, [courseId, visible]);

  const pickAttachments = useCallback(async () => {
    if (
      !enabled ||
      attachments.length >= limit ||
      pickerFlightRef.current ||
      sending ||
      isSendInFlight()
    ) {
      return;
    }

    const pickerCourseId = courseId;
    const pickerGeneration = pickerGenerationRef.current;
    const ownsPicker = () =>
      visibleRef.current &&
      activeCourseRef.current === pickerCourseId &&
      pickerGenerationRef.current === pickerGeneration;
    const selected: ChatAttachmentDraft[] = [];
    let retainedByComposer = false;
    pickerFlightRef.current = true;

    try {
      const boundary = await captureAccountSessionBoundary();
      assertAccountSessionBoundary(boundary);
      const result = await DocumentPicker.getDocumentAsync({
        type: CHAT_ATTACHMENT_MIME_TYPES,
        multiple: true,
        copyToCacheDirectory: true,
      });
      assertAccountSessionBoundary(boundary);
      if (result.canceled || !ownsPicker()) return;

      const remaining = Math.max(0, limit - attachments.length);
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
        assertAccountSessionBoundary(boundary);
        selected.push({
          uri: cached.uri,
          name: cached.fileName || asset.name,
          type: cached.type || asset.mimeType || 'application/octet-stream',
          size: cached.size,
          uploadId: secureRandomUuid(),
        });
        if (!ownsPicker()) return;
      }

      retainedByComposer = true;
      setAttachments(current => {
        const kept = [...current, ...selected].slice(0, limit);
        const keptIds = new Set(kept.map(file => file.uploadId));
        void Promise.all(
          selected
            .filter(file => !keptIds.has(file.uploadId))
            .map(removeLearnerDraftFile),
        );
        return kept;
      });
    } catch (error: unknown) {
      if (
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
      if (!retainedByComposer) {
        await Promise.all(selected.map(removeLearnerDraftFile));
      }
      if (ownsPicker()) pickerFlightRef.current = false;
    }
  }, [
    attachments,
    courseId,
    enabled,
    isSendInFlight,
    limit,
    sending,
    setAttachments,
  ]);

  return {
    pickAttachments,
    pickerIsActive: () => pickerFlightRef.current,
  };
};
