import {publicRequest} from '../../constants/api';
import {isApiRecord, payload} from './common';
import {numericRouteId} from './courseFields';

export type CourseRatingResult = {
  rating: number | null;
  version: number;
  averageRating: number | null;
  ratingsCount: number;
};

const mapCourseRatingMutation = (
  value: unknown,
  expectedRating: number | null,
): CourseRatingResult => {
  if (!isApiRecord(value)) {
    throw new Error('COURSE_RATING_CONTRACT_INVALID');
  }
  const version = Number(value.version);
  const ratingsCount = Number(value.ratings_count);
  const rating = value.rating === null ? null : Number(value.rating);
  const average =
    value.average_rating === null ? null : Number(value.average_rating);
  if (
    !Number.isSafeInteger(version) ||
    version < (expectedRating === null ? 0 : 1) ||
    !Number.isSafeInteger(ratingsCount) ||
    ratingsCount < 0 ||
    rating !== expectedRating ||
    (average !== null &&
      (!Number.isFinite(average) || average < 1 || average > 5)) ||
    (ratingsCount === 0 && average !== null) ||
    (ratingsCount > 0 && average === null)
  ) {
    throw new Error('COURSE_RATING_CONTRACT_INVALID');
  }
  return {rating, version, averageRating: average, ratingsCount};
};

export const rateCourse = async (
  courseId: string,
  rating: number,
  version: number,
): Promise<CourseRatingResult> => {
  const id = numericRouteId(courseId, 'COURSE');
  if (!Number.isInteger(rating) || rating < 1 || rating > 5) {
    throw new Error('INVALID_COURSE_RATING');
  }
  if (!Number.isSafeInteger(version) || version < 0) {
    throw new Error('INVALID_COURSE_RATING_VERSION');
  }
  const data = payload(
    await publicRequest.post(`courses/${id}/rate`, {
      rating,
      version: Math.max(0, Math.floor(version)),
    }),
  );
  return mapCourseRatingMutation(data, rating);
};

export const deleteCourseRating = async (
  courseId: string,
  version: number,
): Promise<CourseRatingResult> => {
  const id = numericRouteId(courseId, 'COURSE');
  if (!Number.isSafeInteger(version) || version < 1) {
    throw new Error('INVALID_COURSE_RATING_VERSION');
  }
  const data = payload(
    await publicRequest.delete(`courses/${id}/rate`, {
      data: {version: Math.max(1, Math.floor(version))},
    }),
  );
  return mapCourseRatingMutation(data, null);
};
