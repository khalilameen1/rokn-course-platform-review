import {requestCourseDetails} from './courseDetailsRequest';
import {courseReadFailureStatus as errorStatus} from './courseReadFailures';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import {payload} from './common';
import {publishUnavailableCourse} from './courseAvailability';
import {mapCourseDetailsPayload, type CourseDetails} from './courseContracts';
import {numericRouteId} from './courseFields';

export const isCourseUnavailableError = (error: unknown): boolean =>
  [403, 404, 410].includes(errorStatus(error));

export type CourseDetailsSnapshot = {
  course: CourseDetails;
  /**
   * The exact successful API envelope used to build `course`. Consumers that
   * need the learning graph project this same immutable response instead of
   * issuing a second details request.
   */
  responsePayload: unknown | null;
};

/**
 * One authoritative details read. The device copy (guests only) is owned by
 * the course-details query; this fetcher never falls back to it, so an owned
 * course can never be unlocked or played from stale entitlements.
 */
export const getCourseDetailsSnapshot = async (
  courseId: string,
  options: {signal?: AbortSignal} = {},
): Promise<CourseDetailsSnapshot> => {
  const id = numericRouteId(courseId, 'COURSE');
  const account = await captureAccountSessionBoundary();
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
    return {course, responsePayload};
  } catch (error) {
    assertAccountSessionBoundary(account);
    // Retired courses leave every catalogue list and device snapshot.
    if ([404, 410].includes(errorStatus(error))) publishUnavailableCourse(id);
    throw error;
  }
};

export const getCourseDetails = async (
  courseId: string,
  options: {signal?: AbortSignal} = {},
): Promise<CourseDetails> =>
  (await getCourseDetailsSnapshot(courseId, options)).course;
