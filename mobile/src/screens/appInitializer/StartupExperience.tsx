import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import {Animated, StyleSheet} from 'react-native';
import {StartupBrand} from '../../components/ui/StartupBrand';
import {useReducedMotion} from '../../hooks/useReducedMotion';
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
  const [visible, setVisible] = useState(true);
  const opacity = useRef(new Animated.Value(1)).current;
  const presentationSession = useRef(createHomePresentationSession()).current;
  const reducedMotion = useReducedMotion();
  const initialContentReady = useCallback(() => setContentReady(true), []);
  useEffect(() => {
    if (!visible) return;
    // A stalled session restore may not trap a ready public catalogue. This
    // deadline never exposes Home's loader; its bounded request must first
    // settle to content, a cached view, or the ordinary retry/offline state.
    const timer = setTimeout(() => setDeadlineReached(true), 8_000);
    return () => clearTimeout(timer);
  }, [visible]);
  useEffect(() => {
    if (!contentReady || (!sessionReady && !deadlineReached)) return;
    if (reducedMotion) {
      setVisible(false);
      return;
    }
    const animation = Animated.timing(opacity, {
      toValue: 0,
      duration: 180,
      useNativeDriver: true,
    });
    animation.start(({finished}) => {
      if (finished) setVisible(false);
    });
    return () => animation.stop();
  }, [contentReady, deadlineReached, opacity, reducedMotion, sessionReady]);
  const value = useMemo(
    () => ({
      readyForPrompts: !visible && sessionReady,
      initialContentReady,
      presentationSession,
    }),
    [initialContentReady, presentationSession, sessionReady, visible],
  );
  return (
    <StartupContext.Provider value={value}>
      {children}
      {visible && (
        <Animated.View
          accessibilityViewIsModal
          testID="startup-cover"
          style={[styles.cover, {opacity}]}>
          <StartupBrand />
        </Animated.View>
      )}
    </StartupContext.Provider>
  );
};
const styles = StyleSheet.create({
  cover: {...StyleSheet.absoluteFillObject, zIndex: 1000, elevation: 30},
});
