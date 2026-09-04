import {useCallback, useEffect, useRef, useState} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';
import {
  saveLessonToFolder,
  toggleWatchLater,
  type SavedFolderOption,
} from '../../components/VideoPlayer/courseLearningApi';
import type {
  CourseLearningData,
  CourseReel,
} from '../../components/VideoPlayer/types';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';

type Params = {
  loadedCourse: MutableRefObject<CourseLearningData | null>;
  mounted: MutableRefObject<boolean>;
  ownerGeneration: MutableRefObject<number>;
  scopeKey: string;
  setConnectionNote: Dispatch<SetStateAction<string>>;
};

export const useReelsSavedLessons = ({
  loadedCourse,
  mounted,
  ownerGeneration,
  scopeKey,
  setConnectionNote,
}: Params) => {
  const [savedLessons, setSavedLessonsState] = useState<Set<string>>(new Set());
  const [savingLessons, setSavingLessons] = useState<Set<string>>(new Set());
  const savedLessonsRef = useRef(savedLessons);
  const pendingRef = useRef(new Set<string>());

  const setSavedLessons = useCallback<Dispatch<SetStateAction<Set<string>>>>(
    update => {
      setSavedLessonsState(current => {
        const next = typeof update === 'function' ? update(current) : update;
        savedLessonsRef.current = next;
        return next;
      });
    },
    [],
  );

  useEffect(() => {
    const empty = new Set<string>();
    savedLessonsRef.current = empty;
    pendingRef.current.clear();
    setSavedLessonsState(empty);
    setSavingLessons(new Set());
  }, [scopeKey]);

  const toggleSaved = useCallback(
    async (reel: CourseReel, folder?: SavedFolderOption | null) => {
      const ownerCourseId = loadedCourse.current?.id;
      if (!ownerCourseId) return;
      const operationGeneration = ownerGeneration.current;
      const operationKey = `${operationGeneration}:${ownerCourseId}:${reel.lessonId}`;
      if (pendingRef.current.has(operationKey)) return;
      pendingRef.current.add(operationKey);
      setSavingLessons(current => new Set(current).add(reel.lessonId));
      const currentlySaved = savedLessonsRef.current.has(reel.lessonId);
      const shouldSave = Boolean(folder) || !currentlySaved;
      let optimisticApplied = false;
      let boundary: Awaited<
        ReturnType<typeof captureAccountSessionBoundary>
      > | null = null;
      const stillOwned = () => {
        if (!boundary) return false;
        try {
          assertAccountSessionBoundary(boundary);
          return (
            mounted.current &&
            ownerGeneration.current === operationGeneration &&
            loadedCourse.current?.id === ownerCourseId
          );
        } catch {
          return false;
        }
      };
      try {
        boundary = await captureAccountSessionBoundary();
        if (loadedCourse.current?.id !== ownerCourseId) return;
        setSavedLessons(current => {
          const next = new Set(current);
          if (shouldSave) next.add(reel.lessonId);
          else next.delete(reel.lessonId);
          return next;
        });
        optimisticApplied = true;
        if (folder) {
          await saveLessonToFolder(reel.lessonId, folder);
        } else {
          await toggleWatchLater(reel.lessonId, currentlySaved);
        }
        assertAccountSessionBoundary(boundary);
        if (!stillOwned()) return;
      } catch (error) {
        if (stillOwned()) {
          if (optimisticApplied) {
            setSavedLessons(current => {
              const next = new Set(current);
              if (currentlySaved) next.add(reel.lessonId);
              else next.delete(reel.lessonId);
              return next;
            });
          }
          setConnectionNote(
            'تعذّر تحديث المحفوظات\nتحقق من الاتصال ثم حاول مرة أخرى',
          );
        }
        throw error;
      } finally {
        if (
          mounted.current &&
          ownerGeneration.current === operationGeneration &&
          loadedCourse.current?.id === ownerCourseId
        ) {
          setSavingLessons(current => {
            const next = new Set(current);
            next.delete(reel.lessonId);
            return next;
          });
        }
        pendingRef.current.delete(operationKey);
      }
    },
    [
      loadedCourse,
      mounted,
      ownerGeneration,
      setConnectionNote,
      setSavedLessons,
    ],
  );

  return {savedLessons, savingLessons, setSavedLessons, toggleSaved};
};
