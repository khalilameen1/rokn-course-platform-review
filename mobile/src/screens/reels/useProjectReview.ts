import {useCallback, useEffect, useRef} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';
import {
  applyLocalLearningState,
  loadCourseLearningData,
} from '../../components/VideoPlayer/courseLearningApi';
import type {CourseLearningData} from '../../components/VideoPlayer/types';
import {buildAccessibleFeed} from './presentation';
import {useAppActiveState} from '../../hooks/useAppActiveState';
import {
  loadProjectResolution,
  watchProjectResolution,
} from '../../components/VideoPlayer/courseLearning/projects';

type ProjectReviewRefs = {
  loadedCourse: MutableRefObject<CourseLearningData | null>;
  mounted: MutableRefObject<boolean>;
  ownerGeneration: MutableRefObject<number>;
  reviewWatcher: MutableRefObject<number>;
  watchedProject: MutableRefObject<string | null>;
};

export const useProjectReview = ({
  active,
  course,
  previewMode,
  refs,
  setCourse,
}: {
  active: boolean;
  course: CourseLearningData | null;
  previewMode: boolean;
  refs: ProjectReviewRefs;
  setCourse: Dispatch<SetStateAction<CourseLearningData | null>>;
}) => {
  const appIsActive = useAppActiveState();
  const reviewActive = active && appIsActive;
  const pendingMapRefreshRef = useRef<string | null>(null);

  useEffect(() => {
    pendingMapRefreshRef.current = null;
  }, [course?.id]);

  const refreshProjectState = useCallback(
    async (projectId: string) => {
      const activeCourseId = course?.id;
      const ownerGeneration = refs.ownerGeneration.current;
      if (!activeCourseId) return null;
      try {
        const result = await loadCourseLearningData(activeCourseId, {
          reconcilePending: false,
        });
        const refreshed = await applyLocalLearningState(result.course);
        if (
          !refs.mounted.current ||
          refs.ownerGeneration.current !== ownerGeneration ||
          refs.loadedCourse.current?.id !== activeCourseId
        ) {
          return null;
        }
        const project = refreshed.modules
          .flatMap(module => module.projects || [])
          .find(item => item?.id === projectId);
        // A missing project is not a fresh "draft". Publishing or account
        // state changed while this review was resolving, so keep the current
        // map and retry instead of replacing it with an unrelated journey.
        if (!project) return null;
        const refreshedFeed = buildAccessibleFeed(refreshed);
        const projectFeedIndex = refreshedFeed.findIndex(
          item => item.type === 'project' && item.project.id === projectId,
        );
        pendingMapRefreshRef.current = null;
        setCourse(refreshed);
        return {
          status: project.status,
          canContinue:
            projectFeedIndex >= 0 &&
            projectFeedIndex < refreshedFeed.length - 1,
        };
      } catch {
        return null;
      }
    },
    [course?.id, refs, setCourse],
  );

  const watchProjectUntilResolved = useCallback(
    (projectId: string) => {
      if (!reviewActive) return;
      if (refs.watchedProject.current === projectId) return;
      refs.watchedProject.current = projectId;
      const watcher = ++refs.reviewWatcher.current;
      watchProjectResolution({
        projectId,
        resolve: async currentProjectId => {
          const resolution = await loadProjectResolution(currentProjectId);
          if (
            resolution.status !== 'passed' &&
            resolution.status !== 'needs_changes'
          ) {
            return {status: resolution.status, canContinue: false};
          }
          // Reload the larger course contract only once after the decision so
          // its newly unlocked manifests and map state arrive together.
          const refreshed = await refreshProjectState(currentProjectId);
          if (refreshed) return refreshed;

          // Knowing the decision is not enough to advance: the course payload
          // owns the newly unlocked section and its signed-media entitlement.
          // Report this poll as still evaluating so the watcher keeps trying
          // after a transient map failure instead of freezing forever on the
          // old project card.
          pendingMapRefreshRef.current = currentProjectId;
          return {status: 'evaluating' as const, canContinue: false};
        },
        isActive: () => reviewActive && refs.reviewWatcher.current === watcher,
        initialDelayMs: 2500,
        onExhausted: () => {
          if (refs.reviewWatcher.current === watcher) {
            refs.watchedProject.current = null;
          }
        },
        onResolution: refreshed => {
          if (
            refreshed.status === 'passed' ||
            refreshed.status === 'needs_changes'
          ) {
            refs.watchedProject.current = null;
          }
        },
      });
    },
    [refreshProjectState, refs, reviewActive],
  );

  useEffect(() => {
    if (reviewActive) return;
    refs.reviewWatcher.current += 1;
    refs.watchedProject.current = null;
  }, [refs, reviewActive]);

  useEffect(() => {
    if (!course || previewMode || !reviewActive) return;
    const pendingProjectId = pendingMapRefreshRef.current;
    const reviewingProject = pendingProjectId
      ? course.modules
          .flatMap(module => module.projects || [])
          .find(project => project?.id === pendingProjectId)
      : course.modules
          .flatMap(module => module.projects || [])
          .find(project => project?.status === 'evaluating');
    if (reviewingProject) watchProjectUntilResolved(reviewingProject.id);
  }, [course, previewMode, reviewActive, watchProjectUntilResolved]);

  return {refreshProjectState, watchProjectUntilResolved};
};
