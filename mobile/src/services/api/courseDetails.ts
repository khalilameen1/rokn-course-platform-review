import {
  DEFAULT_READ_RECOVERY_BUDGET_MS,
  publicRequest,
  type RoknRequestConfig,
} from '../../constants/api';
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

const errorStatus = (error: unknown): number => {
  const failure = error as {status?: unknown; response?: {status?: unknown}};
  return Number(failure.status ?? failure.response?.status ?? 0) || 0;
};

const errorCode = (error: unknown): string => {
  const failure = error as {
    code?: unknown;
    data?: {code?: unknown; data?: {code?: unknown}};
    response?: {data?: {code?: unknown; data?: {code?: unknown}}};
  };
  return String(
    failure.data?.code ??
      failure.data?.data?.code ??
      failure.response?.data?.code ??
      failure.response?.data?.data?.code ??
      failure.code ??
      '',
  )
    .trim()
    .toLowerCase();
};

const isCourseRevisionChangedError = (error: unknown): boolean =>
  errorStatus(error) === 409 && errorCode(error) === 'course_revision_changed';

export const isCourseUnavailableError = (error: unknown): boolean =>
  [403, 404, 410].includes(errorStatus(error));

export const getCourseDetails = async (
  courseId: string,
  options: {signal?: AbortSignal} = {},
): Promise<CourseDetails> => {
  const id = numericRouteId(courseId, 'COURSE');
  const account = await captureAccountSessionBoundary();
  const cacheKey = await accountScopedStorageKey(
    COURSE_DETAILS_CACHE_KEY,
    account,
  );
  const retryDeadlineAt = Date.now() + DEFAULT_READ_RECOVERY_BUDGET_MS;

  try {
    let data: unknown;
    for (let attempt = 0; attempt < 2; attempt += 1) {
      try {
        data = payload(
          await publicRequest.get(`courses/${id}/details`, {
            optionalAuthorization: true,
            roknNetworkRetryDeadlineAt: retryDeadlineAt,
            signal: options.signal,
          } as RoknRequestConfig),
        );
        break;
      } catch (error) {
        if (attempt === 0 && isCourseRevisionChangedError(error)) continue;
        throw error;
      }
    }
    if (!data) throw new Error('API_CONTRACT_INVALID_COURSE_DETAILS');
    assertAccountSessionBoundary(account);
    const course = {...mapCourseDetailsPayload(data), fromCache: false};
    if (!course.id || course.id !== id) {
      throw new Error('API_CONTRACT_INVALID_COURSE_DETAILS_ID');
    }
    void cacheCourseDetails(id, course, cacheKey).catch(() => undefined);
    assertAccountSessionBoundary(account);
    return course;
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
    return cached;
  }
};
