import AsyncStorage from '@react-native-async-storage/async-storage';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {roknCalendarDay} from '../constants/roknCalendar';
import {completeCourseSection} from './api/learningProgress';
import {createDurableOutbox, type DurableOutboxOutcome} from './durableOutbox';
import {updatePlayerStateForScope} from './learningPlayerState';
import {invalidateLearningProgress} from './query/learningInvalidation';
import {hasSession} from './roknApi';

/**
 * Typed M2 adapter: a section completion the server has not acknowledged.
 * Completion is sent online first; only an unacknowledged attempt becomes a
 * durable outbox item, retried on the next lifecycle trigger.
 */
type SectionCompletion = {courseId: string; sectionId: string};

const LEGACY_PREFIX = '@rokn/section-completion/v1';
const flights = new Map<string, Promise<DurableOutboxOutcome>>();
let runtimeGeneration = 0;

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

/** Transient, or a gate the learner can still open (watch, prerequisite). */
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

const validCompletion = (value: unknown): value is SectionCompletion => {
  const candidate = value as Partial<SectionCompletion> | null;
  return Boolean(
    candidate &&
      typeof candidate.courseId === 'string' &&
      candidate.courseId &&
      typeof candidate.sectionId === 'string' &&
      candidate.sectionId,
  );
};

const completionId = ({courseId, sectionId}: SectionCompletion) =>
  `${courseId}:${sectionId}`;

export const sectionCompletionOutbox = createDurableOutbox<SectionCompletion>({
  namespace: '@rokn/section-completion/v2',
  accountScoped: true,
  maxItems: 200,
  ttlMs: 30 * 24 * 60 * 60 * 1000,
  backoff: {baseMs: 5_000, maxMs: 30 * 60 * 1000},
  decode: value =>
    validCompletion(value)
      ? {courseId: value.courseId, sectionId: value.sectionId}
      : null,
  classify: error => (retryable(error) ? 'retry' : 'drop'),
  onDelivered: (payload, scope) =>
    invalidateLearningProgress(scope, payload.courseId),
  legacy: {
    // v1 kept one key per section: `${prefix}:${scope}:${course}:${section}`.
    read: async scope => {
      const prefix = `${LEGACY_PREFIX}:${scope}:`;
      const keys = (await AsyncStorage.getAllKeys()).filter(key =>
        key.startsWith(prefix),
      );
      if (!keys.length) return [];
      return (await AsyncStorage.multiGet(keys)).flatMap(([, raw]) => {
        try {
          const parsed = raw ? JSON.parse(raw) : null;
          return validCompletion(parsed)
            ? [{id: completionId(parsed), payload: parsed}]
            : [];
        } catch {
          return [];
        }
      });
    },
    clear: async scope => {
      const prefix = `${LEGACY_PREFIX}:${scope}:`;
      const keys = (await AsyncStorage.getAllKeys()).filter(key =>
        key.startsWith(prefix),
      );
      await Promise.all(keys.map(key => AsyncStorage.removeItem(key)));
    },
  },
});

const assertOwner = (generation: number, boundary: AccountSessionBoundary) => {
  if (generation !== runtimeGeneration) {
    throw new Error('ACCOUNT_SESSION_CHANGED');
  }
  assertAccountSessionBoundary(boundary);
};

const owned = (generation: number, boundary: AccountSessionBoundary) => {
  try {
    assertOwner(generation, boundary);
    return true;
  } catch {
    return false;
  }
};

/** The deliver function: one POST per account epoch and section. */
const deliver = (
  completion: SectionCompletion,
  boundary: AccountSessionBoundary,
  generation: number,
): Promise<DurableOutboxOutcome> => {
  assertOwner(generation, boundary);
  const key = `${boundary.scope}:${boundary.epoch}:${completionId(completion)}`;
  const existing = flights.get(key);
  if (existing) return existing;
  const flight = (async (): Promise<DurableOutboxOutcome> => {
    if (!(await hasSession())) return 'keep';
    assertOwner(generation, boundary);
    try {
      await completeCourseSection(completion.courseId, completion.sectionId);
    } catch (error) {
      assertOwner(generation, boundary);
      return sectionCompletionOutbox.policy.classify!(error);
    }
    assertOwner(generation, boundary);
    // Only the acknowledged server result owns delivery. The optional
    // player-cache repair never delays the next learning step.
    void updatePlayerStateForScope(
      boundary.scope,
      state => ({
        ...state,
        completedSections: Array.from(
          new Set([...state.completedSections, completion.sectionId]),
        ),
        activityDays: Array.from(
          new Set([...state.activityDays, roknCalendarDay()]),
        ).slice(-60),
      }),
      boundary,
    ).catch(() => undefined);
    return 'ack';
  })().finally(() => {
    if (flights.get(key) === flight) flights.delete(key);
  });
  flights.set(key, flight);
  return flight;
};

export const markSectionComplete = async (
  courseId: string,
  sectionId: string,
): Promise<boolean> => {
  const generation = runtimeGeneration;
  const boundary = await captureAccountSessionBoundary();
  const completion = {courseId, sectionId};
  const id = completionId(completion);
  let outcome: DurableOutboxOutcome;
  try {
    outcome = await deliver(completion, boundary, generation);
  } catch {
    return false;
  }
  if (!owned(generation, boundary)) return false;
  if (outcome === 'ack') {
    invalidateLearningProgress(boundary.scope, courseId);
    // Retiring an older retry record runs in the outbox's own order.
    void sectionCompletionOutbox.remove(id, {boundary}).catch(() => undefined);
    return true;
  }
  if (outcome === 'retry') {
    // An unacknowledged completion still requires its durable retry record.
    if (!(await sectionCompletionOutbox.get(id, {boundary}))) {
      await sectionCompletionOutbox.enqueue(id, completion, {boundary});
    }
  } else if (outcome === 'drop') {
    await sectionCompletionOutbox.remove(id, {boundary});
  }
  return false;
};

/** Lifecycle trigger: replays every unacknowledged completion of this owner. */
export const retryPendingSectionCompletions = async () => {
  const generation = runtimeGeneration;
  const boundary = await captureAccountSessionBoundary();
  assertOwner(generation, boundary);
  if (!(await hasSession())) return;
  assertOwner(generation, boundary);
  try {
    // Reuse the owner captured before reading its durable queue: account B
    // can never send account A's completion.
    await sectionCompletionOutbox.flush({
      boundary,
      ignoreBackoff: true,
      deliver: item => deliver(item.payload, boundary, generation),
    });
  } catch {
    // An account change retires this pass; the record stays for its owner.
  }
};

export const resetSectionCompletionRuntime = () => {
  runtimeGeneration += 1;
  flights.clear();
};
