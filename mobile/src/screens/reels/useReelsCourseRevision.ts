import {useCallback, useEffect} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';

import {subscribeCourseRevisionChanges} from '../../components/VideoPlayer/courseLearningApi';
import type {
  CourseLearningData,
  CourseReel,
} from '../../components/VideoPlayer/types';
import type {CourseReloadTarget} from './useReelsCourseLoader';
import {buildAccessibleFeed} from './presentation';

type Params = {
  activeReel: MutableRefObject<CourseReel | undefined>;
  closedSessions: MutableRefObject<Set<string>>;
  currentIndex: MutableRefObject<number>;
  invalidateManifests: () => void;
  load: (target?: CourseReloadTarget) => Promise<void>;
  loadedCourse: MutableRefObject<CourseLearningData | null>;
  mounted: MutableRefObject<boolean>;
  pending: MutableRefObject<boolean>;
  reloadFlight: MutableRefObject<Promise<void> | null>;
  setConnectionNote: Dispatch<SetStateAction<string>>;
  setRefreshing: Dispatch<SetStateAction<boolean>>;
};

export const useReelsCourseRevision = ({
  activeReel,
  closedSessions,
  currentIndex,
  invalidateManifests,
  load,
  loadedCourse,
  mounted,
  pending,
  reloadFlight,
  setConnectionNote,
  setRefreshing,
}: Params) => {
  const reload = useCallback(
    (lessonId?: string, projectId?: string) => {
      if (reloadFlight.current) return;
      pending.current = true;
      setRefreshing(true);
      invalidateManifests();
      closedSessions.current.clear();
      setConnectionNote('تم تحديث الكورس\nنعرض أحدث نسخة');
      let succeeded = false;
      let projectChanged = false;
      const flight = load({
        lessonId: projectId
          ? undefined
          : lessonId || activeReel.current?.lessonId,
        ...(projectId ? {projectId} : {}),
        index: currentIndex.current,
        onResult: (result, reason) => {
          succeeded = result;
          projectChanged = reason === 'project_changed';
        },
      }).finally(() => {
        if (reloadFlight.current !== flight) return;
        reloadFlight.current = null;
        if (succeeded) pending.current = false;
        if (!mounted.current) return;
        if (succeeded) {
          setRefreshing(false);
        } else if (projectChanged) {
          // The source editor owns renewing its prepared draft destination.
          // Do not offer a generic reload that would discard that transition.
          setRefreshing(false);
          setConnectionNote('تغيّر المشروع مرة أخرى\nراجع المشروع المحدّث');
        } else {
          setConnectionNote('تغيّر محتوى الكورس\nاضغط لإعادة التحميل');
        }
      });
      reloadFlight.current = flight;
    },
    [
      activeReel,
      closedSessions,
      currentIndex,
      invalidateManifests,
      load,
      mounted,
      pending,
      reloadFlight,
      setConnectionNote,
      setRefreshing,
    ],
  );

  useEffect(() => {
    const unsubscribe = subscribeCourseRevisionChanges(change => {
      const current = loadedCourse.current;
      const ownsSourceLesson = Boolean(
        change.sourceLessonId &&
          current?.modules.some(module =>
            module.reels.some(reel => reel.lessonId === change.sourceLessonId),
          ),
      );
      if (String(current?.id || '') !== change.courseId && !ownsSourceLesson) {
        return;
      }
      const activeItem = current
        ? buildAccessibleFeed(current)[currentIndex.current]
        : undefined;
      // A background project review may also announce publication. Only move
      // to its replacement when that project's card is currently being viewed.
      const projectId =
        activeItem?.type === 'project' &&
        activeItem.project.id === change.sourceProjectId
          ? change.currentProjectId
          : undefined;
      reload(change.currentLessonId, projectId);
    });
    return () => {
      unsubscribe();
    };
  }, [currentIndex, loadedCourse, reload]);

  return reload;
};
