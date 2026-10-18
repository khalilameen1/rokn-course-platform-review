import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import {publicRequest} from '../../constants/api';
import {getLearningCourses, type CourseProgress} from './courses';
import {
  firstBoolean,
  isApiRecord,
  isResourceListPayload,
  payload,
  resourceList,
} from './common';

type EarnedBadgeDto = {
  order?: unknown;
  id?: unknown;
  level_id?: unknown;
  name_ar?: unknown;
  name_en?: unknown;
  badge_image?: unknown;
  course_id?: unknown;
  course_name_ar?: unknown;
  course_name_en?: unknown;
  track?: unknown;
  earned_at?: unknown;
};

type ProfileLearningDto = {earned_badges?: unknown};
type StreakDayDto = {has_streak?: unknown; date?: unknown};
type StreakDto = {
  week?: {days?: unknown};
  current_streak?: unknown;
  last_streak_before_gap?: unknown;
};

type LearningPathLevelDto = {
  id?: unknown;
  name_ar?: unknown;
  name_en?: unknown;
  badge_image_url?: unknown;
  order?: unknown;
};

type LearningPathDto = {
  path?: {
    id?: unknown;
    title?: unknown;
    title_ar?: unknown;
    title_en?: unknown;
  };
  current_level?: LearningPathLevelDto | null;
  next_level?: LearningPathLevelDto | null;
  levels?: unknown;
  progress_percentage?: unknown;
  required_progress_percentage?: unknown;
  completed_sections?: unknown;
  total_sections?: unknown;
};

export type LearningCourse = CourseProgress;

export type LearningDashboard = {
  courses: LearningCourse[];
  paths: LearningPathProgress[];
  badges: Array<{
    order?: number;
    id: string;
    levelId?: string;
    title: string;
    imageUrl?: string;
    courseId?: string;
    courseTitle?: string;
    track?: string;
    earnedAt?: string;
  }>;
  activityDays: string[];
  currentStreakDays: number;
  /** Present when fresh courses arrived but a secondary panel stayed cached. */
  partialError?: string;
};

export type LearningPathLevel = {
  id: string;
  name: string;
  imageUrl?: string;
  order: number;
};

export type LearningPathProgress = {
  id: string;
  title: string;
  currentLevel?: LearningPathLevel;
  nextLevel?: LearningPathLevel;
  upcomingLevels: LearningPathLevel[];
  progress: number;
  remainingToNextLevel: number;
  completedSections: number;
  totalSections: number;
};

const mapPathLevel = (
  level?: LearningPathLevelDto | null,
): LearningPathLevel | undefined => {
  if (level === null || level === undefined) return undefined;
  if (
    !isApiRecord(level) ||
    level.id === null ||
    level.id === undefined ||
    !/^\d+$/.test(String(level.id).trim()) ||
    (!String(level.name_ar || '').trim() &&
      !String(level.name_en || '').trim()) ||
    !Number.isSafeInteger(Number(level.order)) ||
    Number(level.order) < 0
  ) {
    throw new Error('LEARNING_PATHS_CONTRACT_INVALID');
  }
  return {
    id: String(level.id),
    name: String(level.name_ar || level.name_en || 'المستوى التالي'),
    imageUrl: level.badge_image_url ? String(level.badge_image_url) : undefined,
    order: Math.max(0, Number(level.order) || 0),
  };
};

const getLearningPaths = async (): Promise<LearningPathProgress[]> => {
  const data = payload<unknown>(await publicRequest.get('user/paths'));
  if (!isResourceListPayload(data)) {
    throw new Error('LEARNING_PATHS_CONTRACT_INVALID');
  }
  return resourceList<LearningPathDto>(data).map(item => {
    if (
      !isApiRecord(item) ||
      !isApiRecord(item.path) ||
      item.path.id === null ||
      item.path.id === undefined
    ) {
      throw new Error('LEARNING_PATHS_CONTRACT_INVALID');
    }
    const currentLevel = mapPathLevel(item.current_level);
    const nextLevel = mapPathLevel(item.next_level);
    const seenLevelIds = new Set<string>();
    if (!isResourceListPayload(item.levels)) {
      throw new Error('LEARNING_PATHS_CONTRACT_INVALID');
    }
    const upcomingLevels = resourceList<LearningPathLevelDto>(item.levels)
      .map(level => {
        const mapped = mapPathLevel(level);
        if (
          !mapped ||
          mapped.id === currentLevel?.id ||
          seenLevelIds.has(mapped.id)
        ) {
          throw new Error('LEARNING_PATHS_CONTRACT_INVALID');
        }
        seenLevelIds.add(mapped.id);
        return mapped;
      })
      .sort((left, right) => left.order - right.order);
    if (nextLevel && upcomingLevels[0]?.id !== nextLevel.id) {
      throw new Error('LEARNING_PATHS_CONTRACT_INVALID');
    }
    return {
      id: String(item.path.id),
      title: String(
        item.path.title ||
          item.path.title_ar ||
          item.path.title_en ||
          'مسارك المهني',
      ),
      currentLevel,
      nextLevel,
      upcomingLevels,
      progress: Math.min(
        100,
        Math.max(0, Number(item.progress_percentage) || 0),
      ),
      remainingToNextLevel: Math.min(
        100,
        Math.max(0, Number(item.required_progress_percentage) || 0),
      ),
      completedSections: Math.max(0, Number(item.completed_sections) || 0),
      totalSections: Math.max(0, Number(item.total_sections) || 0),
    };
  });
};

type LearningDashboardReadOptions = {
  /** Courses own the learning entry; enrichment never delays them. */
  onCoursesReady?: (courses: LearningCourse[]) => void;
  /**
   * The last dashboard this account showed (in memory or restored from the
   * device). A failed secondary panel keeps its last known value from here.
   */
  previous?: LearningDashboard | null;
};

export const getLearningDashboard = async ({
  onCoursesReady,
  previous = null,
}: LearningDashboardReadOptions = {}): Promise<LearningDashboard> => {
  const accountBoundary = await captureAccountSessionBoundary();
  const cachedDashboard = previous;
  const coursesRequest = getLearningCourses();
  const secondaryRequest = Promise.allSettled([
    publicRequest.get('user/profile', {
      params: {include_learning: 0, include_badges: 1},
    }),
    publicRequest.get('streaks'),
    getLearningPaths(),
  ]);
  // Publish the authoritative library (including an empty one) independently.
  // Secondary failures are observed from the start, even if courses reject.
  const courses = await coursesRequest;
  assertAccountSessionBoundary(accountBoundary);
  onCoursesReady?.(courses);
  const dashboardResults = await secondaryRequest;
  const [profileResult, streakResult, pathsResult] = dashboardResults;
  assertAccountSessionBoundary(accountBoundary);
  const partialFailure = [profileResult, streakResult, pathsResult].some(
    result => result.status === 'rejected',
  );
  const profile =
    profileResult.status === 'fulfilled'
      ? payload<ProfileLearningDto>(profileResult.value)
      : {};
  const streak =
    streakResult.status === 'fulfilled'
      ? payload<StreakDto>(streakResult.value)
      : {};
  const dashboard: LearningDashboard = {
    courses,
    paths:
      pathsResult.status === 'fulfilled'
        ? pathsResult.value
        : cachedDashboard?.paths || [],
    badges:
      profileResult.status === 'fulfilled'
        ? resourceList<EarnedBadgeDto>(profile.earned_badges).flatMap(badge => {
            const id = String(badge.id ?? '').trim();
            if (!id) return [];
            return [
              {
                id,
                order:
                  Number.isSafeInteger(Number(badge.order)) &&
                  Number(badge.order) > 0
                    ? Number(badge.order)
                    : undefined,
                levelId: badge.level_id ? String(badge.level_id) : undefined,
                title: String(badge.name_ar || badge.name_en || 'شارة مهنية'),
                imageUrl: badge.badge_image
                  ? String(badge.badge_image)
                  : undefined,
                courseId: badge.course_id ? String(badge.course_id) : undefined,
                courseTitle:
                  badge.course_name_ar || badge.course_name_en
                    ? String(badge.course_name_ar || badge.course_name_en)
                    : undefined,
                track: badge.track ? String(badge.track) : undefined,
                earnedAt: badge.earned_at ? String(badge.earned_at) : undefined,
              },
            ];
          })
        : cachedDashboard?.badges || [],
    activityDays:
      streakResult.status === 'fulfilled'
        ? resourceList<StreakDayDto>(streak.week?.days)
            .filter(
              day =>
                firstBoolean(day?.has_streak) === true &&
                typeof day?.date === 'string',
            )
            .map(day => String(day.date))
        : cachedDashboard?.activityDays || [],
    currentStreakDays:
      streakResult.status === 'fulfilled'
        ? Math.max(
            0,
            Number(
              streak.current_streak ?? streak.last_streak_before_gap ?? 0,
            ) || 0,
          )
        : cachedDashboard?.currentStreakDays || 0,
    ...(partialFailure
      ? {partialError: 'تعذّر تحديث بعض بيانات تقدمك\nنعرض آخر نسخة متاحة'}
      : {}),
  };
  assertAccountSessionBoundary(accountBoundary);
  return dashboard;
};
