import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';

const ATTACHMENT_PROMPT_SEEN = 'course-attachment-prompt-seen:v1';

const seenKey = async (
  courseId: string,
  moduleId: string,
  boundary: AccountSessionBoundary,
) =>
  `${await accountScopedStorageKey(
    ATTACHMENT_PROMPT_SEEN,
    boundary,
  )}:${encodeURIComponent(courseId)}:${encodeURIComponent(moduleId)}`;

export const hasSeenAttachmentPrompt = async (
  courseId: string,
  moduleId: string,
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const seen =
    (await AsyncStorage.getItem(
      await seenKey(courseId, moduleId, boundary),
    )) === '1';
  assertAccountSessionBoundary(boundary);
  return seen;
};

export const markAttachmentPromptSeen = async (
  courseId: string,
  moduleId: string,
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  await AsyncStorage.setItem(await seenKey(courseId, moduleId, boundary), '1');
  assertAccountSessionBoundary(boundary);
};
