import {
  DEFAULT_READ_RECOVERY_BUDGET_MS,
  publicRequest,
  type RoknRequestConfig,
} from '../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import {firstBoolean, isApiRecord, payload} from './common';
import {
  mapLearningCoursesPayload,
  type CourseProgress,
} from './courseContracts';

export const getLearningCourses = async (
  options: {signal?: AbortSignal; retryDeadlineAt?: number} = {},
): Promise<CourseProgress[]> => {
  const accountBoundary = await captureAccountSessionBoundary();
  const retryDeadlineAt =
    options.retryDeadlineAt ?? Date.now() + DEFAULT_READ_RECOVERY_BUDGET_MS;
  const courses: CourseProgress[] = [];
  const courseIds = new Set<string>();
  const cursors = new Set<string>();
  let cursor: string | undefined;

  for (let page = 0; page < 100; page += 1) {
    assertAccountSessionBoundary(accountBoundary);
    const data = payload<unknown>(
      await publicRequest.get('learning/courses', {
        signal: options.signal,
        params: {per_page: 100, ...(cursor ? {cursor} : {})},
        roknNetworkRetryDeadlineAt: retryDeadlineAt,
      } as RoknRequestConfig),
    );
    assertAccountSessionBoundary(accountBoundary);

    const pageCourses = mapLearningCoursesPayload(data);
    if (!isApiRecord(data) || !isApiRecord(data.pagination)) {
      throw new Error('LEARNING_COURSES_PAGINATION_CONTRACT_INVALID');
    }
    const hasMore = firstBoolean(data.pagination.has_more);
    const nextCursor =
      typeof data.pagination.next_cursor === 'string'
        ? data.pagination.next_cursor.trim()
        : '';
    if (hasMore === undefined || (hasMore && !nextCursor)) {
      throw new Error('LEARNING_COURSES_PAGINATION_CONTRACT_INVALID');
    }

    pageCourses.forEach(course => {
      if (courseIds.has(course.id)) {
        throw new Error('LEARNING_COURSES_PAGINATION_CONTRACT_INVALID');
      }
      courseIds.add(course.id);
      courses.push(course);
    });

    if (!hasMore) return courses;
    if (cursors.has(nextCursor)) {
      throw new Error('LEARNING_COURSES_PAGINATION_CONTRACT_INVALID');
    }
    cursors.add(nextCursor);
    cursor = nextCursor;
  }

  throw new Error('LEARNING_COURSES_PAGE_LIMIT_EXCEEDED');
};
