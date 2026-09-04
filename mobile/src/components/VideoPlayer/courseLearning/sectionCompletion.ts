import AsyncStorage from '@react-native-async-storage/async-storage';

import {publicRequest} from '../../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {roknCalendarDay} from '../../../constants/roknCalendar';
import {hasSession} from '../../../services/roknApi';
import {updatePlayerStateForScope} from './persistence';

const STORAGE_PREFIX = '@rokn/section-completion/v1';
const flights = new Map<string, Promise<boolean>>();
let runtimeGeneration = 0;

const assertOwner = (generation: number, boundary: AccountSessionBoundary) => {
  if (generation !== runtimeGeneration) {
    throw new Error('ACCOUNT_SESSION_CHANGED');
  }
  assertAccountSessionBoundary(boundary);
};

const storagePrefix = (accountScope: string) =>
  `${STORAGE_PREFIX}:${accountScope}:`;

const storageKey = (
  courseId: string,
  sectionId: string,
  accountScope: string,
) => `${storagePrefix(accountScope)}${courseId}:${sectionId}`;

const responseStatus = (error: unknown): number | null => {
  if (!error || typeof error !== 'object') return null;
  const candidate = error as {
    status?: unknown;
    response?: {status?: unknown};
  };
  const value = Number(candidate.status ?? candidate.response?.status);
  return Number.isFinite(value) && value > 0 ? value : null;
};

const errorCode = (error: unknown): string => {
  if (!error || typeof error !== 'object') return '';
  const candidate = error as {
    code?: unknown;
    data?: {code?: unknown};
    response?: {data?: {code?: unknown}};
  };
  return String(
    candidate.response?.data?.code ??
      candidate.data?.code ??
      candidate.code ??
      '',
  )
    .trim()
    .toLowerCase();
};

const retryable = (error: unknown) => {
  const status = responseStatus(error);
  if (status === null || status === 408 || status === 429 || status >= 500) {
    return true;
  }
  return [
    'verified_watch_required',
    'previous_section_incomplete',
    'module_project_not_passed',
    'section_locked',
  ].includes(errorCode(error));
};

const complete = async (
  courseId: string,
  sectionId: string,
  boundary: AccountSessionBoundary,
  generation: number,
) => {
  assertOwner(generation, boundary);
  if (!(await hasSession())) return false;
  assertOwner(generation, boundary);

  const key = storageKey(courseId, sectionId, boundary.scope);
  try {
    await publicRequest.post(
      `courses/${courseId}/sections/${sectionId}/complete`,
    );
  } catch (error) {
    try {
      assertOwner(generation, boundary);
    } catch {
      return false;
    }
    if (retryable(error)) {
      await AsyncStorage.setItem(key, JSON.stringify({courseId, sectionId}));
      try {
        assertOwner(generation, boundary);
      } catch {
        await AsyncStorage.removeItem(key);
        return false;
      }
    } else {
      await AsyncStorage.removeItem(key);
    }
    return false;
  }

  try {
    assertOwner(generation, boundary);
  } catch {
    return false;
  }
  try {
    await updatePlayerStateForScope(
      boundary.scope,
      state => ({
        ...state,
        completedSections: Array.from(
          new Set([...state.completedSections, sectionId]),
        ),
        activityDays: Array.from(
          new Set([...state.activityDays, roknCalendarDay()]),
        ).slice(-60),
      }),
      boundary,
    );
    assertOwner(generation, boundary);
    await AsyncStorage.removeItem(key);
  } catch {
    // The server decision is authoritative; local repair stays idempotent.
  }
  return true;
};

const completeForOwner = (
  courseId: string,
  sectionId: string,
  boundary: AccountSessionBoundary,
  generation: number,
) => {
  assertOwner(generation, boundary);
  const key = `${boundary.scope}:${boundary.epoch}:${courseId}:${sectionId}`;
  const existing = flights.get(key);
  if (existing) return existing;
  const flight = complete(courseId, sectionId, boundary, generation).finally(
    () => {
      if (flights.get(key) === flight) flights.delete(key);
    },
  );
  flights.set(key, flight);
  return flight;
};

export const markSectionComplete = async (
  courseId: string,
  sectionId: string,
) => {
  const generation = runtimeGeneration;
  const boundary = await captureAccountSessionBoundary();
  return completeForOwner(courseId, sectionId, boundary, generation);
};

export const retryPendingSectionCompletions = async () => {
  const generation = runtimeGeneration;
  const boundary = await captureAccountSessionBoundary();
  assertOwner(generation, boundary);
  if (!(await hasSession())) return;
  assertOwner(generation, boundary);

  const prefix = storagePrefix(boundary.scope);
  const keys = (await AsyncStorage.getAllKeys()).filter(key =>
    key.startsWith(prefix),
  );
  assertOwner(generation, boundary);
  if (!keys.length) return;
  const entries = await AsyncStorage.multiGet(keys);
  assertOwner(generation, boundary);

  for (const [key, value] of entries) {
    assertOwner(generation, boundary);
    if (!value) continue;
    let pending: {courseId: string; sectionId: string};
    try {
      pending = JSON.parse(value) as typeof pending;
      if (!pending.courseId || !pending.sectionId) {
        throw new Error('INVALID_SECTION_COMPLETION');
      }
    } catch {
      await AsyncStorage.removeItem(key);
      continue;
    }
    try {
      // Reuse the owner captured before reading its durable queue. Calling
      // the public function here could switch to account B between the read
      // and the POST, applying account A's completion to the wrong learner.
      await completeForOwner(
        pending.courseId,
        pending.sectionId,
        boundary,
        generation,
      );
      assertOwner(generation, boundary);
    } catch {
      try {
        assertOwner(generation, boundary);
      } catch {
        return;
      }
    }
  }
};

export const resetSectionCompletionRuntime = () => {
  runtimeGeneration += 1;
  flights.clear();
};
