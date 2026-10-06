import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import {hide as hideNativeSplash} from 'expo-splash-screen';
import {createHomePresentationSession} from './homePresentationSession';

type StartupContextValue = {
  readyForPrompts: boolean;
  initialContentReady: () => void;
  presentationSession: ReturnType<typeof createHomePresentationSession>;
};
const StartupContext = createContext<StartupContextValue | null>(null);
export const useStartupExperience = () => useContext(StartupContext);

export const StartupExperience = ({
  children,
  sessionReady,
}: {
  children: React.ReactNode;
  sessionReady: boolean;
}) => {
  const [contentReady, setContentReady] = useState(false);
  const [deadlineReached, setDeadlineReached] = useState(false);
  const [released, setReleased] = useState(false);
  const releaseStarted = useRef(false);
  const presentationSession = useRef(createHomePresentationSession()).current;
  const initialContentReady = useCallback(() => setContentReady(true), []);
  useEffect(() => {
    if (released) return;
    // A stalled session restore may not trap a ready public catalogue. This
    // deadline never exposes Home's loader; its bounded request must first
    // settle to content, a cached view, or the ordinary retry/offline state.
    const timer = setTimeout(() => setDeadlineReached(true), 8_000);
    return () => clearTimeout(timer);
  }, [released]);
  useEffect(() => {
    if (releaseStarted.current || !contentReady || (!sessionReady && !deadlineReached)) return;
    releaseStarted.current = true;
    // Expo owns the only launch screen. No second React logo, fade, or minimum
    // duration; with fade disabled the committed ready screen is the handoff.
    hideNativeSplash();
    setReleased(true);
  }, [contentReady, deadlineReached, sessionReady]);
  const value = useMemo(
    () => ({
      readyForPrompts: released && sessionReady,
      initialContentReady,
      presentationSession,
    }),
    [initialContentReady, presentationSession, sessionReady, released],
  );
  return (
    <StartupContext.Provider value={value}>
      {children}
    </StartupContext.Provider>
  );
};
