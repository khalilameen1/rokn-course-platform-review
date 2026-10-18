import type {
  LearningCourse,
  LearningDashboard,
  LearningPathProgress,
} from './learning';

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

/**
 * Validates a learning dashboard restored from the device snapshot (moved
 * unchanged from the previous `@rokn/learning-dashboard/v3` reader). A
 * damaged or foreign record is dropped rather than shown.
 */
export const normalizeCachedLearningDashboard = (
  value: unknown,
): LearningDashboard | null => {
  if (!isRecord(value)) return null;
  if (
    !Array.isArray(value.courses) ||
    value.courses.some(
      course =>
        !isRecord(course) ||
        typeof course.id !== 'string' ||
        !/^[1-9]\d*$/.test(course.id) ||
        typeof course.title !== 'string' ||
        course.title.trim().length === 0 ||
        !Number.isFinite(course.progress) ||
        Number(course.progress) < 0 ||
        Number(course.progress) > 100 ||
        !Number.isFinite(course.completedSections) ||
        !Number.isSafeInteger(course.completedSections) ||
        Number(course.completedSections) < 0 ||
        !Number.isFinite(course.totalSections) ||
        !Number.isSafeInteger(course.totalSections) ||
        Number(course.totalSections) < 1 ||
        Number(course.completedSections) > Number(course.totalSections) ||
        !['freelance', 'language', 'religious'].includes(
          String(course.category || ''),
        ) ||
        typeof course.accessType !== 'string' ||
        course.accessType.length === 0 ||
        typeof course.chatAvailable !== 'boolean' ||
        typeof course.certificateAvailable !== 'boolean' ||
        (course.nextSectionType !== undefined &&
          (!['lesson', 'project'].includes(String(course.nextSectionType)) ||
            typeof course.nextSectionId !== 'string' ||
            !/^[1-9]\d*$/.test(course.nextSectionId) ||
            typeof course.nextSectionTitle !== 'string' ||
            course.nextSectionTitle.trim().length === 0)),
    ) ||
    !Array.isArray(value.paths) ||
    value.paths.some(
      path =>
        !isRecord(path) ||
        typeof path.id !== 'string' ||
        path.id.length === 0 ||
        typeof path.title !== 'string' ||
        !Array.isArray(path.upcomingLevels) ||
        !Number.isFinite(path.progress) ||
        !Number.isFinite(path.remainingToNextLevel),
    ) ||
    !Array.isArray(value.badges) ||
    value.badges.some(
      badge =>
        !isRecord(badge) ||
        typeof badge.id !== 'string' ||
        badge.id.length === 0 ||
        typeof badge.title !== 'string',
    ) ||
    !Array.isArray(value.activityDays) ||
    value.activityDays.some(
      day => typeof day !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(day),
    )
  ) {
    return null;
  }
  const courses = value.courses as LearningCourse[];
  const paths = value.paths as LearningPathProgress[];
  const badges = value.badges as LearningDashboard['badges'];
  const activityDays = value.activityDays as string[];
  const currentStreakDays = Number(value.currentStreakDays);
  if (!Number.isFinite(currentStreakDays)) return null;
  return {
    courses: courses.slice(0, 100),
    paths: paths.slice(0, 50),
    badges: badges.slice(0, 200),
    activityDays: Array.from(new Set(activityDays)).slice(-31),
    currentStreakDays: Math.max(0, Math.floor(currentStreakDays)),
  };
};
