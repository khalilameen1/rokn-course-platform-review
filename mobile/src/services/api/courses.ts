import {extractApiToken} from '../../constants/helpers';
import {peekSecureSession} from '../secureSession';

export {
  getCachedPublishedCourses,
  getPublishedCourses,
  getPublishedCoursesPage,
} from './courseCatalogue';
export {getCourseDetails, isCourseUnavailableError} from './courseDetails';
export {getLearningCourses} from './learningCourses';
export {
  deleteCourseRating,
  rateCourse,
  type CourseRatingResult,
} from './courseRatings';
export {subscribeToUnavailableCourses} from './courseAvailability';
export type {
  CourseAccessPlan,
  CourseDetails,
  CourseModulePreview,
  CourseProgress,
  PublishedCoursesPage,
} from './courseContracts';

export const hasSession = async () => {
  const snapshot = peekSecureSession();
  return snapshot.ready && Boolean(extractApiToken(snapshot.session));
};
