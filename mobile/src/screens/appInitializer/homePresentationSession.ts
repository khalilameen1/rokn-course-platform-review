/** One presentation slot per app launch, shared across Home remounts. */
export const createHomePresentationSession = () => {
  let identity: string | null = null;
  let consumed = false;
  return {
    observeIdentity(next: string) {
      // Signing in or switching accounts must not immediately open another
      // marketing card. A new app launch creates a new session instead.
      if (identity !== null && identity !== next) consumed = true;
      identity = next;
    },
    available() {
      return !consumed;
    },
    reserve() {
      if (consumed) return false;
      consumed = true;
      return true;
    },
    suppress() {
      consumed = true;
    },
  };
};

export const WELCOME_PRESENTED_KEY = '@rokn/home-welcome/presented/v1';
