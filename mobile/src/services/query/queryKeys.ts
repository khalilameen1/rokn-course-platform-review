/**
 * Query key factory (TanStack Query v5 "Query Keys" guide: array keys, most
 * generic segment first, so one prefix addresses a whole family).
 *
 * Rokn has two families:
 * - `public`: account-neutral discovery (the published catalogue).
 * - an account storage scope (`user-…` / `guest-…`, from
 *   `constants/helpers.ts`): everything a learner owns. The scope is always
 *   the first segment, so an account switch can remove every query owned by
 *   the previous scope with one predicate, and a screen rendered for the new
 *   scope can never read the previous scope's entry.
 */
export const PUBLIC_QUERY_SCOPE = 'public';

/** Every key that is not public belongs to exactly one account scope. */
export const isAccountScope = (value: unknown): value is string =>
  typeof value === 'string' && value !== '' && value !== PUBLIC_QUERY_SCOPE;

export const queryKeyScope = (queryKey: readonly unknown[]) => queryKey[0];

export const isAccountScopedQueryKey = (queryKey: readonly unknown[]) =>
  isAccountScope(queryKeyScope(queryKey));

export const publicQueryKeys = {
  all: [PUBLIC_QUERY_SCOPE] as const,
  catalogue: () => [PUBLIC_QUERY_SCOPE, 'catalogue'] as const,
  cataloguePages: (search: string) =>
    [PUBLIC_QUERY_SCOPE, 'catalogue', search] as const,
};

/**
 * A disabled placeholder scope used while the current account scope is still
 * being resolved. It never matches a real scope, so no data is shown for it.
 */
export const UNRESOLVED_ACCOUNT_SCOPE = 'unresolved-account';

export const accountQueryKeys = (scope: string | null) => {
  const owner = scope ?? UNRESOLVED_ACCOUNT_SCOPE;
  return {
    all: [owner] as const,
    /** Whether this scope currently holds a signed-in bearer. */
    session: () => [owner, 'session'] as const,
    learningCourses: () => [owner, 'learning-courses'] as const,
    courseDetails: (courseId: string) =>
      [owner, 'course-details', courseId] as const,
    learningDashboard: () => [owner, 'learning-dashboard'] as const,
    savedFolders: () => [owner, 'saved-folders'] as const,
    wallet: () => [owner, 'wallet'] as const,
    coinTasks: () => [owner, 'coin-tasks'] as const,
  };
};
