import {
  courseReadFailureStatus as errorStatus,
  isCourseRevisionChangedError,
  requestCourseDetails,
} from './courseDetailsRequest';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import {settleWithin} from '../../utils/settleWithin';
import {payload} from './common';
import {publishUnavailableCourse} from './courseAvailability';
import {
  CATALOGUE_CACHE_KEY,
  COURSE_DETAILS_CACHE_KEY,
  cacheCourseDetails,
  readCourseDetailsCache,
  removeCatalogueCachePages,
  removeCourseDetailsCache,
  touchCourseDetailsCache,
} from './courseCache';
import {mapCourseDetailsPayload, type CourseDetails} from './courseContracts';
import {numericRouteId} from './courseFields';

export const isCourseUnavailableError = (error: unknown): boolean =>
  [403, 404, 410].includes(errorStatus(error));

export type CourseDetailsSnapshot = {
  course: CourseDetails;
  /**
   * The exact successful API envelope used to build `course`. Consumers that
   * need the learning graph project this same immutable response instead of
   * issuing a second details request. Cached guest details deliberately have
   * no learning envelope because they never drive owned-course playback.
   */
  responsePayload: unknown | null;
};

export const getCourseDetailsSnapshot = async (
  courseId: string,
  options: {signal?: AbortSignal} = {},
): Promise<CourseDetailsSnapshot> => {
  const id = numericRouteId(courseId, 'COURSE');
  const account = await captureAccountSessionBoundary();
  const cacheKey = await accountScopedStorageKey(
    COURSE_DETAILS_CACHE_KEY,
    account,
  );
  try {
    const response = await requestCourseDetails(id, {
      optionalAuthorization: true,
      signal: options.signal,
    });
    const responsePayload: unknown = response.data;
    const data = payload(response);
    if (!data) throw new Error('API_CONTRACT_INVALID_COURSE_DETAILS');
    assertAccountSessionBoundary(account);
    const course = {...mapCourseDetailsPayload(data), fromCache: false};
    if (!course.id || course.id !== id) {
      throw new Error('API_CONTRACT_INVALID_COURSE_DETAILS_ID');
    }
    void cacheCourseDetails(id, course, cacheKey).catch(() => undefined);
    assertAccountSessionBoundary(account);
    return {course, responsePayload};
  } catch (error) {
    assertAccountSessionBoundary(account);
    if (isCourseRevisionChangedError(error)) {
      await settleWithin(removeCourseDetailsCache(id, cacheKey), undefined);
      throw error;
    }
    if (isCourseUnavailableError(error)) {
      await settleWithin(removeCourseDetailsCache(id, cacheKey), undefined);
      if ([404, 410].includes(errorStatus(error))) {
        const catalogueKey = await accountScopedStorageKey(
          CATALOGUE_CACHE_KEY,
          account,
        );
        await settleWithin(
          removeCatalogueCachePages(1, catalogueKey),
          undefined,
        );
        publishUnavailableCourse(id);
      }
      throw error;
    }
    if (!account.scope.startsWith('guest-')) throw error;
    const cached = await settleWithin(
      readCourseDetailsCache(id, cacheKey, true),
      null,
    );
    if (!cached) throw error;
    await settleWithin(touchCourseDetailsCache(id, cacheKey), undefined);
    assertAccountSessionBoundary(account);
    return {course: cached, responsePayload: null};
  }
};

export const getCourseDetails = async (
  courseId: string,
  options: {signal?: AbortSignal} = {},
): Promise<CourseDetails> =>
  (await getCourseDetailsSnapshot(courseId, options)).course;
