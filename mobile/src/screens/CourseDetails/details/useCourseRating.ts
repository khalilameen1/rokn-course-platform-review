import {useCallback, useEffect, useRef, useState} from 'react';
import type {Dispatch, SetStateAction} from 'react';
import {
  deleteCourseRating,
  rateCourse,
  type CourseDetails,
} from '../../../services/roknApi';
import {learnerErrorMessage} from '../../../utils/errorPayload';

type CourseRatingInput = {
  course: CourseDetails | null;
  courseId: string;
  identityKey: string;
  owned: boolean;
  reload: () => void;
  serverSession: boolean | null;
  setCourse: Dispatch<SetStateAction<CourseDetails | null>>;
  setNotice: (message: string) => void;
};

export const useCourseRating = ({
  course,
  courseId,
  identityKey,
  owned,
  reload,
  serverSession,
  setCourse,
  setNotice,
}: CourseRatingInput) => {
  const [busy, setBusy] = useState(false);
  const [rating, setRating] = useState<number | null>(null);
  const inFlightRef = useRef(false);
  const generationRef = useRef(0);
  const scopeRef = useRef({courseId, identityKey});

  useEffect(() => {
    scopeRef.current = {courseId, identityKey};
    generationRef.current += 1;
    inFlightRef.current = false;
    setBusy(false);
    setRating(null);
  }, [courseId, identityKey]);

  useEffect(() => {
    setRating(course?.userRating ?? null);
  }, [course?.userRating, courseId, identityKey]);

  const owns = useCallback(
    (expectedCourseId: string, expectedIdentity: string, generation: number) =>
      scopeRef.current.courseId === expectedCourseId &&
      scopeRef.current.identityKey === expectedIdentity &&
      generationRef.current === generation,
    [],
  );

  const updateCourse = useCallback(
    (result: {
      rating: number | null;
      version: number;
      averageRating: number | null;
      ratingsCount: number;
    }) => {
      setCourse(current =>
        current
          ? {
              ...current,
              userRating: result.rating,
              ratingVersion: result.version,
              ratingAverage: result.averageRating,
              ratingsCount: result.ratingsCount,
            }
          : current,
      );
    },
    [setCourse],
  );

  const submit = useCallback(
    async (value: number) => {
      const version = course?.ratingVersion;
      if (
        inFlightRef.current ||
        busy ||
        serverSession !== true ||
        !owned ||
        !course?.ratingEligible ||
        version === undefined
      ) {
        return;
      }
      const operation = {courseId, identityKey, generation: generationRef.current};
      inFlightRef.current = true;
      setBusy(true);
      setNotice('');
      try {
        const result = await rateCourse(courseId, value, version);
        if (!owns(operation.courseId, operation.identityKey, operation.generation)) {
          return;
        }
        setRating(result.rating);
        updateCourse(result);
      } catch (error) {
        if (!owns(operation.courseId, operation.identityKey, operation.generation)) {
          return;
        }
        setNotice(learnerErrorMessage(error, 'تعذّر حفظ التقييم'));
        reload();
      } finally {
        if (owns(operation.courseId, operation.identityKey, operation.generation)) {
          inFlightRef.current = false;
          setBusy(false);
        }
      }
    },
    [
      busy,
      course,
      courseId,
      identityKey,
      owned,
      owns,
      reload,
      serverSession,
      setNotice,
      updateCourse,
    ],
  );

  const remove = useCallback(async () => {
    const version = course?.ratingVersion;
    if (inFlightRef.current || busy || !rating || !version) return;
    const operation = {courseId, identityKey, generation: generationRef.current};
    inFlightRef.current = true;
    setBusy(true);
    setNotice('');
    try {
      const result = await deleteCourseRating(courseId, version);
      if (!owns(operation.courseId, operation.identityKey, operation.generation)) {
        return;
      }
      setRating(null);
      updateCourse({...result, rating: null});
    } catch (error) {
      if (!owns(operation.courseId, operation.identityKey, operation.generation)) {
        return;
      }
      setNotice(learnerErrorMessage(error, 'تعذّر حذف التقييم'));
      reload();
    } finally {
      if (owns(operation.courseId, operation.identityKey, operation.generation)) {
        inFlightRef.current = false;
        setBusy(false);
      }
    }
  }, [
    busy,
    course?.ratingVersion,
    courseId,
    identityKey,
    owns,
    rating,
    reload,
    setNotice,
    updateCourse,
  ]);

  return {busy, rating, remove, submit};
};
