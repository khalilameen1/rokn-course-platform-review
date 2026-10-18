import {useCallback, useEffect, useRef, useState} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';
import {
  removeLessonFromSavedFolder,
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
import {learnerCopy} from '../../constants/learnerCopy';

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
  const savedLessonsVersion = useRef(0);
  const pendingRef = useRef(new Set<string>());

  const setSavedLessons = useCallback<Dispatch<SetStateAction<Set<string>>>>(
    update => {
      savedLessonsVersion.current += 1;
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
    savedLessonsVersion.current += 1;
    pendingRef.current.clear();
    setSavedLessonsState(empty);
    setSavingLessons(new Set());
  }, [scopeKey]);

  /**
   * One owner for every bookmark command on a reel: optimistic icon, the
   * server write, the confirmed final state and rollback on failure.
   * Resolves true only when the server confirmed this owner's command.
   */
  const runSavedCommand = useCallback(
    async (
      reel: CourseReel,
      optimisticSaved: boolean,
      execute: () => Promise<boolean>,
    ): Promise<boolean> => {
      const ownerCourseId = loadedCourse.current?.id;
      if (!ownerCourseId) return false;
      const operationGeneration = ownerGeneration.current;
      const operationKey = `${operationGeneration}:${ownerCourseId}:${reel.lessonId}`;
      if (pendingRef.current.has(operationKey)) return false;
      pendingRef.current.add(operationKey);
      setSavingLessons(current => new Set(current).add(reel.lessonId));
      const currentlySaved = savedLessonsRef.current.has(reel.lessonId);
      const reflectSaved = (isSaved: boolean) =>
        setSavedLessons(current => {
          const next = new Set(current);
          if (isSaved) next.add(reel.lessonId);
          else next.delete(reel.lessonId);
          return next;
        });
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
        if (loadedCourse.current?.id !== ownerCourseId) return false;
        reflectSaved(optimisticSaved);
        optimisticApplied = true;
        const confirmedSaved = await execute();
        assertAccountSessionBoundary(boundary);
        if (!stillOwned()) return false;
        // A background saved-state read may have landed while the write was
        // pending. The confirmed command owns this reel's final bookmark state.
        reflectSaved(confirmedSaved);
        return true;
      } catch (error) {
        if (stillOwned()) {
          if (optimisticApplied) {
            reflectSaved(currentlySaved);
          }
          setConnectionNote(`معرفناش نحدّث المحفوظات\n${learnerCopy.networkRetry}`);
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
    [loadedCourse, mounted, ownerGeneration, setConnectionNote, setSavedLessons],
  );

  const toggleSaved = useCallback(
    (reel: CourseReel, folder?: SavedFolderOption | null): Promise<boolean> => {
      // undefined means the explicit default destination; null is the only
      // remove-everywhere command. A reel saved in another list must still be
      // addable to "المشاهدة لاحقًا" without deleting its existing memberships.
      const shouldSave = folder !== null;
      return runSavedCommand(reel, shouldSave, async () => {
        if (folder) {
          await saveLessonToFolder(reel.lessonId, folder);
        } else {
          await toggleWatchLater(reel.lessonId, folder === null);
        }
        return shouldSave;
      });
    },
    [runSavedCommand],
  );

  /**
   * Removes the reel from one list only (Facebook "Saved": leaving a
   * collection is not unsaving). The bookmark stays filled while another list
   * holds it. stillSavedElsewhere is the caller's membership view; the server's
   * still_saved answer wins when it sends one.
   */
  const removeFromList = useCallback(
    (
      reel: CourseReel,
      folder: SavedFolderOption,
      stillSavedElsewhere: boolean,
    ): Promise<boolean> =>
      runSavedCommand(reel, stillSavedElsewhere, async () => {
        const {stillSaved} = await removeLessonFromSavedFolder(
          reel.lessonId,
          folder.id,
        );
        return stillSaved ?? stillSavedElsewhere;
      }),
    [runSavedCommand],
  );

  return {
    savedLessons,
    savedLessonsVersion,
    savingLessons,
    removeFromList,
    setSavedLessons,
    toggleSaved,
  };
};
