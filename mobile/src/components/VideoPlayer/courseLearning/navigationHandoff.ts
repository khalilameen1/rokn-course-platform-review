import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import type {CourseLearningData} from '../types';

export type FreshLearningRead = {
  course: CourseLearningData;
  boundary: AccountSessionBoundary;
  receivedAt: number;
};

const MAX_READ_AGE_MS = 30_000;

/** A one-use screen transition, not a persistent entitlement/media cache.
 * Only a fresh details response may prepare it. Reloads and restored routes
 * still read the server; protected video always needs its signed manifest. */
export const createLearningNavigationHandoff = () => {
  let serial = 0;
  let pending: (FreshLearningRead & {key: string}) | null = null;
  const fresh = (read: FreshLearningRead) => {
    const age = Date.now() - read.receivedAt;
    return Number.isFinite(age) && age >= 0 && age < MAX_READ_AGE_MS;
  };
  return {
    prepare(read: FreshLearningRead): string | undefined {
      pending = null;
      if (!/^\d+$/.test(read.course.id) || !fresh(read)) return undefined;
      try {
        assertAccountSessionBoundary(read.boundary);
      } catch {
        return undefined;
      }
      const key = `learning-transition-${++serial}`;
      pending = {...read, key};
      return key;
    },
    take(
      key: string | undefined,
      courseId: string,
      boundary: AccountSessionBoundary,
    ): CourseLearningData | null {
      if (!pending || !key || pending.key !== key) return null;
      const read = pending;
      pending = null;
      if (
        read.course.id !== courseId ||
        read.boundary.scope !== boundary.scope ||
        read.boundary.epoch !== boundary.epoch ||
        !fresh(read)
      )
        return null;
      try {
        assertAccountSessionBoundary(boundary);
        assertAccountSessionBoundary(read.boundary);
        return read.course;
      } catch {
        return null;
      }
    },
    clear() {
      pending = null;
    },
  };
};

export const learningNavigationHandoff = createLearningNavigationHandoff();
