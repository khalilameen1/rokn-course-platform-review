import {useQuery} from '@tanstack/react-query';
import {useCallback} from 'react';
import {assertAccountSessionBoundary} from '../../constants/helpers';
import {getLearningCourses, hasSession} from '../../services/roknApi';
import {
  captureScopedBoundary,
  useAccountQueryScope,
} from '../../services/query/accountQueryScope';
import {accountQueryKeys} from '../../services/query/queryKeys';

type Params = {
  active: boolean;
  appIsActive: boolean;
  identityKey: string;
};

const NO_COURSES: never[] = [];

/**
 * Account-owned facts used to decorate the public catalogue.
 *
 * This hook deliberately does not load or clear published courses. Restoring,
 * replacing, or losing a session must never make the guest catalogue vanish.
 * Both reads are keyed by the account scope, so another account's ownership
 * can never decorate this account's cards. They refresh on every return to
 * Home or to the app (`staleTime: 0`, resubscribed by `subscribed: active`).
 */
export const useCourseAccessOverlay = ({active, identityKey}: Params) => {
  const {scope} = useAccountQueryScope(identityKey);
  const keys = accountQueryKeys(scope);

  const session = useQuery({
    queryKey: keys.session(),
    queryFn: () => hasSession().catch(() => false),
    enabled: scope !== null,
    subscribed: active,
    staleTime: 0,
  });
  const courses = useQuery({
    queryKey: keys.learningCourses(),
    queryFn: async ({signal}) => {
      const boundary = await captureScopedBoundary(scope!);
      const next = await getLearningCourses({signal});
      assertAccountSessionBoundary(boundary);
      return next;
    },
    // Course details owns the final access decision. A failed decorative
    // read keeps the last known decoration and never becomes an error screen.
    enabled: scope !== null && session.data === true,
    subscribed: active,
    staleTime: 0,
  });

  const refetchSession = session.refetch;
  const refetchCourses = courses.refetch;
  const refresh = useCallback(async () => {
    if (!scope) return;
    // `refetch` ignores `enabled`; a guest must never call the owned read.
    const latest = await refetchSession();
    if (latest.data === true) await refetchCourses();
  }, [refetchCourses, refetchSession, scope]);

  return {
    courses: session.data === true ? courses.data ?? NO_COURSES : NO_COURSES,
    refresh,
    session: scope === null ? null : session.data ?? null,
  };
};
