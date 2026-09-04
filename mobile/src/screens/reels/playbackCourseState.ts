import type {
  CourseLearningData,
  CourseReel,
} from '../../components/VideoPlayer/types';
import type {PlaybackManifest} from '../../components/VideoPlayer/courseLearningApi';

type ManifestUpdate = {
  courseId?: string;
  lessonId: string;
  expectedSessionId?: string;
  manifest: PlaybackManifest;
  revision: number;
};

export const applyPlaybackManifest = (
  course: CourseLearningData | null,
  update: ManifestUpdate,
): CourseLearningData | null => {
  if (!course || course.id !== update.courseId) return course;
  let changed = false;
  const modules = course.modules.map(module => ({
    ...module,
    reels: module.reels.map(existing => {
      if (existing.lessonId !== update.lessonId) return existing;
      const sessionIsCurrent = update.expectedSessionId
        ? existing.playbackSessionId === update.expectedSessionId
        : !existing.playbackSessionId;
      if (!sessionIsCurrent) return existing;
      changed = true;
      return {
        ...existing,
        videoUrl: update.manifest.sourceUrl,
        fallbackVideoUrl:
          update.manifest.fallbackUrl || existing.fallbackVideoUrl,
        thumbnailUrl: update.manifest.posterUrl || existing.thumbnailUrl,
        qualitySources: update.manifest.qualitySources,
        availableQualities: update.manifest.availableQualities,
        durationSeconds:
          update.manifest.durationSeconds || existing.durationSeconds,
        playbackSessionId: update.manifest.playbackSessionId,
        playbackProtocol: update.manifest.protocol,
        playbackExpiresAt: update.manifest.expiresAt,
        playbackRefreshAfter: update.manifest.refreshAfter,
        playbackManifestRevision: update.revision,
        mediaStatus: update.manifest.mediaStatus,
      } satisfies CourseReel;
    }),
  }));
  return changed ? {...course, modules} : course;
};

export const disableLessonPlayback = (
  course: CourseLearningData | null,
  courseId: string | undefined,
  lessonId: string,
  lockReason?: string,
): CourseLearningData | null => {
  if (!course || course.id !== courseId) return course;
  return {
    ...course,
    modules: course.modules.map(module => ({
      ...module,
      reels: module.reels.map(existing =>
        existing.lessonId === lessonId
          ? {
              ...existing,
              videoUrl: '',
              fallbackVideoUrl: undefined,
              playbackSessionId: undefined,
              mediaStatus: 'failed',
              ...(lockReason
                ? {isLocked: true, lockReason}
                : {}),
            }
          : existing,
      ),
    })),
  };
};

export const playbackFeatureErrorCode = (error: unknown): string => {
  if (!error || typeof error !== 'object') return '';
  const candidate = error as {
    code?: unknown;
    data?: {code?: unknown};
    response?: {data?: {code?: unknown}};
  };
  return String(
    candidate.response?.data?.code ?? candidate.data?.code ?? candidate.code ?? '',
  );
};

export const playbackManifestHttpStatus = (error: unknown): number | null => {
  if (!error || typeof error !== 'object') return null;
  const candidate = error as {
    status?: unknown;
    response?: {status?: unknown};
  };
  const status = Number(candidate.status ?? candidate.response?.status);
  return Number.isFinite(status) && status > 0 ? status : null;
};
