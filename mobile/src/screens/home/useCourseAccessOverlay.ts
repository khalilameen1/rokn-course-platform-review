import {useCallback, useEffect, useRef, useState} from 'react';
import type {CourseProgress} from '../../services/api/courses';
import {getLearningCourses, hasSession} from '../../services/roknApi';
import {networkFailureKind} from '../../services/networkExperience';

type Params = {
  active: boolean;
  appIsActive: boolean;
  identityKey: string;
};

/**
 * Account-owned facts used to decorate the public catalogue.
 *
 * This hook deliberately does not load or clear published courses. Restoring,
 * replacing, or losing a session must never make the guest catalogue vanish.
 */
export const useCourseAccessOverlay = ({
  active,
  appIsActive,
  identityKey,
}: Params) => {
  const [courses, setCourses] = useState<CourseProgress[]>([]);
  const [session, setSession] = useState<boolean | null>(null);
  const ownerRef = useRef(identityKey);
  const controllerRef = useRef<AbortController | null>(null);
  const requestIdRef = useRef(0);
  const wasActiveRef = useRef(active);
  const wasAppActiveRef = useRef(appIsActive);

  const refresh = useCallback(async () => {
    const owner = identityKey;
    const requestId = ++requestIdRef.current;
    controllerRef.current?.abort();
    controllerRef.current = null;

    const sessionAvailable = await hasSession().catch(() => false);
    if (requestId !== requestIdRef.current || ownerRef.current !== owner) {
      return;
    }

    setSession(sessionAvailable);
    if (!sessionAvailable) {
      setCourses([]);
      return;
    }

    const controller = new AbortController();
    controllerRef.current = controller;
    try {
      const next = await getLearningCourses({signal: controller.signal});
      if (requestId === requestIdRef.current && ownerRef.current === owner) {
        setCourses(next);
      }
    } catch (error) {
      if (networkFailureKind(error) === 'cancelled') return;
      // Course details owns the final access decision. A failed decorative
      // read must not turn Home into an error screen.
    } finally {
      if (
        requestId === requestIdRef.current &&
        controllerRef.current === controller
      ) {
        controllerRef.current = null;
      }
    }
  }, [identityKey]);

  useEffect(() => {
    ownerRef.current = identityKey;
    controllerRef.current?.abort();
    controllerRef.current = null;
    requestIdRef.current += 1;
    setCourses([]);
    setSession(null);
    void refresh();

    return () => {
      controllerRef.current?.abort();
      controllerRef.current = null;
      requestIdRef.current += 1;
    };
  }, [identityKey, refresh]);

  useEffect(() => {
    const returnedToScreen = active && !wasActiveRef.current;
    const returnedToApp = appIsActive && !wasAppActiveRef.current;
    wasActiveRef.current = active;
    wasAppActiveRef.current = appIsActive;

    if (active && appIsActive && (returnedToScreen || returnedToApp)) {
      void refresh();
    }
  }, [active, appIsActive, refresh]);

  return {
    courses: ownerRef.current === identityKey ? courses : [],
    refresh,
    session: ownerRef.current === identityKey ? session : null,
  };
};
