import type {
  CourseFeedItem,
  CourseLearningData,
  VideoQuality,
} from '../../components/VideoPlayer/types';
import {
  courseLearningGateState,
  orderedCourseSteps,
} from '../../components/VideoPlayer/courseLearning/sequence';
export type {ReelsRouteParams} from '../../navigation/types';

export const PLAYBACK_PREFERENCE_BITRATE_KBPS: Record<
  VideoQuality,
  number | undefined
> = {
  auto: undefined,
  '1080p': 5000,
  '720p': 2800,
  '480p': 1300,
  '360p': 750,
};

export const buildAccessibleFeed = (
  course: CourseLearningData,
): CourseFeedItem[] => {
  const items: CourseFeedItem[] = [];
  for (const location of orderedCourseSteps(course)) {
    const {module, step, stepIndex, steps} = location;
    const gate = courseLearningGateState(module, steps, stepIndex);
    if (gate === 'locked_purchase' || gate === 'locked_project') return items;
    const item: CourseFeedItem =
      step.type === 'reel'
        ? {
            key: `reel-${step.reel.id}`,
            type: 'reel',
            moduleId: module.id,
            reel: step.reel,
          }
        : {
            key: `project-${step.project.id}`,
            type: 'project',
            moduleId: module.id,
            project: step.project,
          };
    items.push(item);
    if (item.type === 'project' && item.project.status !== 'passed')
      return items;
  }
  return items;
};

export const buildPreviewFeed = (
  course: CourseLearningData,
): CourseFeedItem[] => {
  const allReels = course.modules
    .flatMap(module => module.reels.map(reel => ({moduleId: module.id, reel})))
    .filter(item => item.reel.videoUrl.trim());
  const previewReels = allReels.filter(item => item.reel.isPreview);

  return previewReels.map(({moduleId, reel}) => ({
    key: `reel-${reel.id}`,
    type: 'reel' as const,
    moduleId,
    reel: {...reel, isLocked: false},
  }));
};

export type ReelsFeedAnchor = {
  reelId?: string | number;
  lessonId?: string | number;
  projectId?: string | number;
  continueAfterReelId?: string | number;
};

/**
 * Resolve external route identities once into the feed's canonical item key.
 * A lesson id and a reel id are different backend identities even when some
 * fixtures happen to give them the same value.
 */
export const resolveReelsFeedAnchor = (
  items: CourseFeedItem[],
  anchor: ReelsFeedAnchor,
): {index: number; item: CourseFeedItem} | null => {
  const continueAfterReelId = String(anchor.continueAfterReelId || '').trim();
  if (continueAfterReelId) {
    const completedPreviewIndex = items.findIndex(
      item =>
        item.type === 'reel' && item.reel.id === continueAfterReelId,
    );
    const nextIndex = completedPreviewIndex + 1;
    if (completedPreviewIndex >= 0 && nextIndex < items.length) {
      return {index: nextIndex, item: items[nextIndex]};
    }
  }
  const reelId = String(anchor.reelId || '').trim();
  const lessonId = String(anchor.lessonId || '').trim();
  const projectId = String(anchor.projectId || '').trim();
  let index = reelId
    ? items.findIndex(item => item.type === 'reel' && item.reel.id === reelId)
    : -1;
  if (index < 0 && lessonId) {
    index = items.findIndex(
      item => item.type === 'reel' && item.reel.lessonId === lessonId,
    );
  }
  if (index < 0 && projectId) {
    index = items.findIndex(
      item => item.type === 'project' && item.project.id === projectId,
    );
  }
  return index >= 0 ? {index, item: items[index]} : null;
};

export const updateProjectStatusOnly = (
  course: CourseLearningData,
  projectId: string,
  status: 'evaluating' | 'passed' | 'needs_changes',
): CourseLearningData => ({
  ...course,
  modules: course.modules.map(module => {
    const projects = module.projects || [];
    if (!projects.some(project => project.id === projectId)) return module;
    return {
      ...module,
      projects: projects.map(project =>
        project.id === projectId ? {...project, status} : project,
      ),
    };
  }),
});

export const resolveReelsFrameWidth = ({
  width,
  height,
}: {
  width: number;
  height: number;
}) => {
  if (!width || !height) return 0;
  // A phone remains full-bleed in portrait. In landscape, using the raw
  // device width turns the vertical reel into a wide crop (especially on
  // small Android 7 phones whose landscape width is still below the tablet
  // breakpoint). Keep the same vertical stage used on tablets instead.
  if (width < 700 && width <= height) return width;
  return Math.min(width, 620, Math.round(height * 0.625));
};
