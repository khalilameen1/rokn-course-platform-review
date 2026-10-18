import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {learnerDraftStorage} from './learnerDraftStorage';

/**
 * Device storage for one course or lesson chat history. The key layout is a
 * persisted contract (`learnerDraftStorage.courseChat`, account first), so
 * file cleanup can find the attachments a history still references.
 */
const COURSE_CHAT_HISTORY_PREFIX = learnerDraftStorage.courseChat.namespace;

export const courseChatHistoryKey = async (
  courseId: string,
  lessonId?: string,
  boundary?: AccountSessionBoundary,
) =>
  `${COURSE_CHAT_HISTORY_PREFIX}:${
    boundary?.scope || (await captureAccountSessionBoundary()).scope
  }:${encodeURIComponent(courseId)}:${encodeURIComponent(
    lessonId || 'course',
  )}`;

export const readCourseChatHistory = (storageKey: string) =>
  AsyncStorage.getItem(storageKey);

export const writeCourseChatHistory = (storageKey: string, raw: string) =>
  AsyncStorage.setItem(storageKey, raw);
