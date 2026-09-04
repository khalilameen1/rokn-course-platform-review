import {useCallback, useEffect, useRef, useState} from 'react';
import type {Dispatch, SetStateAction} from 'react';

import type {CourseLearningData} from '../../components/VideoPlayer/types';

type Params = {
  identityKey: string;
  requestedCourseId: string;
  scopeKey: string;
};

/**
 * Owns the loaded course aggregate and its request generation. Every course
 * mutation passes through setCourse, which updates the state and the async
 * guard ref together instead of letting manifests/progress mutate a shadow.
 */
export const useReelsCourseState = ({
  identityKey,
  requestedCourseId,
  scopeKey,
}: Params) => {
  const loadedCourseRef = useRef<CourseLearningData | null>(null);
  const loadedCourseOwnerRef = useRef(identityKey);
  const requestedScopeRef = useRef(scopeKey);
  const accountViewGenerationRef = useRef(0);
  const loadRequestRef = useRef(0);
  const loadAbortRef = useRef<AbortController | null>(null);
  const courseRevisionReloadRef = useRef<Promise<void> | null>(null);
  const courseRevisionPendingRef = useRef(false);
  const [loadedCourse, setLoadedCourse] = useState<CourseLearningData | null>(
    null,
  );
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [connectionNote, setConnectionNote] = useState('');
  const [courseRevisionRefreshing, setCourseRevisionRefreshing] =
    useState(false);
  const [learningMapRetryIndex, setLearningMapRetryIndex] = useState<
    number | null
  >(null);
  const [serverSession, setServerSession] = useState<boolean | null>(null);

  const setCourse = useCallback<
    Dispatch<SetStateAction<CourseLearningData | null>>
  >(value => {
    setLoadedCourse(current => {
      const next = typeof value === 'function' ? value(current) : value;
      loadedCourseRef.current = next;
      return next;
    });
  }, []);

  const course =
    loadedCourseOwnerRef.current === identityKey &&
    requestedScopeRef.current === scopeKey &&
    String(loadedCourse?.id || '') === requestedCourseId
      ? loadedCourse
      : null;

  useEffect(() => {
    requestedScopeRef.current = scopeKey;
    loadRequestRef.current += 1;
    loadAbortRef.current?.abort();
    loadAbortRef.current = null;
    accountViewGenerationRef.current += 1;
    loadedCourseRef.current = null;
    loadedCourseOwnerRef.current = identityKey;
    courseRevisionReloadRef.current = null;
    courseRevisionPendingRef.current = false;
    setLoadedCourse(null);
    setLoading(true);
    setLoadError('');
    setServerSession(null);
    setConnectionNote('');
    setCourseRevisionRefreshing(false);
    setLearningMapRetryIndex(null);
  }, [identityKey, scopeKey]);

  return {
    accountViewGenerationRef,
    connectionNote,
    course,
    courseRevisionPendingRef,
    courseRevisionRefreshing,
    courseRevisionReloadRef,
    learningMapRetryIndex,
    loadAbortRef,
    loadError,
    loading,
    loadRequestRef,
    loadedCourseOwnerRef,
    loadedCourseRef,
    serverSession,
    setConnectionNote,
    setCourse,
    setCourseRevisionRefreshing,
    setLearningMapRetryIndex,
    setLoadError,
    setLoading,
    setServerSession,
  };
};
