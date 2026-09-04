import {useCallback, useEffect} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';

import {subscribeCourseRevisionChanges} from '../../components/VideoPlayer/courseLearningApi';
import type {
  CourseLearningData,
  CourseReel,
} from '../../components/VideoPlayer/types';
import type {CourseReloadTarget} from './useReelsCourseLoader';

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
    (lessonId?: string) => {
      if (reloadFlight.current) return;
      pending.current = true;
      setRefreshing(true);
      invalidateManifests();
      closedSessions.current.clear();
      setConnectionNote('تم تحديث الكورس\nنعرض أحدث نسخة');
      let succeeded = false;
      const flight = load({
        lessonId: lessonId || activeReel.current?.lessonId,
        index: currentIndex.current,
        onResult: result => {
          succeeded = result;
        },
      }).finally(() => {
        if (reloadFlight.current !== flight) return;
        reloadFlight.current = null;
        if (succeeded) pending.current = false;
        if (!mounted.current) return;
        if (succeeded) {
          setRefreshing(false);
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
      reload(change.currentLessonId);
    });
    return () => {
      unsubscribe();
    };
  }, [loadedCourse, reload]);

  return reload;
};
