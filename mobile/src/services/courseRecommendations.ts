import type {Course} from '../types/Course';

export const DEFAULT_RECOMMENDATION_LIMIT = 10;
export const MINIMUM_RECOMMENDATION_COUNT = 4;

type RecommendationOptions = {
  /** Courses already occupying the current context, such as the Home hero. */
  excludedCourseIds?: readonly string[];
  /** Categories inferred only from courses the learner already opened. */
  preferredCategories?: readonly Course['category'][];
  limit?: number;
  minimumResults?: number;
};

type RankedCourse = {
  course: Course;
  originalIndex: number;
  baseScore: number;
};

const boundedHomeOrder = (value: unknown): number => {
  if (value === null || value === undefined || value === '') return 100;
  const parsed = Number(value);
  return Math.max(0, Math.min(1000, Number.isFinite(parsed) ? parsed : 100));
};

const isActionableCourse = (course: Course): boolean => {
  const progress = Number(course.progress || 0);
  return (
    Boolean(String(course.id || '').trim()) &&
    Boolean(String(course.title || '').trim()) &&
    course.published !== false &&
    course.owned !== true &&
    progress <= 0
  );
};

const rankCourse = (
  course: Course,
  originalIndex: number,
  preferredCategories: ReadonlySet<Course['category']>,
): RankedCourse => {
  const configuredOrder = boundedHomeOrder(course.homeSortOrder);
  const baseScore =
    (preferredCategories.has(course.category) ? 240 : 0) +
    (course.isMainCourse ? 90 : 0) +
    Math.max(0, 100 - configuredOrder) +
    Math.min(3, course.homeRows?.length || 0) * 12 +
    (course.labelTone === 'success'
      ? 20
      : course.labelTone === 'primary'
      ? 14
      : course.labelTone === 'coin'
      ? 8
      : 0);

  return {course, originalIndex, baseScore};
};

/**
 * Produces a stable, diverse course shelf from the catalogue already loaded by
 * the app. It never persists learner profiling and never changes access state.
 */
export const recommendCourses = (
  courses: readonly Course[],
  options: RecommendationOptions = {},
): Course[] => {
  const minimumResults = Math.max(
    1,
    Math.floor(options.minimumResults ?? MINIMUM_RECOMMENDATION_COUNT),
  );
  const limit = Math.max(
    minimumResults,
    Math.floor(options.limit ?? DEFAULT_RECOMMENDATION_LIMIT),
  );
  const excludedIds = new Set(
    (options.excludedCourseIds || []).map(id => String(id).trim()),
  );
  const preferredCategories = new Set(options.preferredCategories || []);
  const seenIds = new Set<string>();

  const remaining = courses
    .map((course, originalIndex) => ({course, originalIndex}))
    .filter(({course}) => {
      const id = String(course.id || '').trim();
      if (
        seenIds.has(id) ||
        excludedIds.has(id) ||
        !isActionableCourse(course)
      ) {
        return false;
      }
      seenIds.add(id);
      return true;
    })
    .map(({course, originalIndex}) =>
      rankCourse(course, originalIndex, preferredCategories),
    );

  if (remaining.length < minimumResults) return [];

  const selected: Course[] = [];
  const categoryUsage = new Map<Course['category'], number>();

  // A small repeat penalty keeps adjacent suggestions varied without making
  // the result random or hiding the strongest course in a relevant category.
  while (remaining.length && selected.length < limit) {
    remaining.sort((first, second) => {
      const firstAdjusted =
        first.baseScore - (categoryUsage.get(first.course.category) || 0) * 84;
      const secondAdjusted =
        second.baseScore -
        (categoryUsage.get(second.course.category) || 0) * 84;
      return (
        secondAdjusted - firstAdjusted ||
        first.originalIndex - second.originalIndex
      );
    });
    const next = remaining.shift();
    if (!next) break;
    selected.push(next.course);
    categoryUsage.set(
      next.course.category,
      (categoryUsage.get(next.course.category) || 0) + 1,
    );
  }

  return selected;
};
