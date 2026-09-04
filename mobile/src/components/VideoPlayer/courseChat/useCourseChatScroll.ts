import {useCallback, useEffect, useRef} from 'react';
import type {ScrollView} from 'react-native';

/** Owns every delayed conversation scroll so a closed/replaced chat cannot
 * move the reels sheet after it has lost the screen. */
export const useCourseChatScroll = (visible: boolean) => {
  const scrollRef = useRef<ScrollView>(null);
  const timersRef = useRef(new Set<ReturnType<typeof setTimeout>>());

  const scheduleScrollToEnd = useCallback(
    (animated: boolean, delayMs: number) => {
      const timer = setTimeout(() => {
        timersRef.current.delete(timer);
        scrollRef.current?.scrollToEnd({animated});
      }, delayMs);
      timersRef.current.add(timer);
    },
    [],
  );

  useEffect(() => {
    const timers = timersRef.current;
    if (visible) scheduleScrollToEnd(false, 100);
    return () => {
      timers.forEach(clearTimeout);
      timers.clear();
    };
  }, [scheduleScrollToEnd, visible]);

  return {scheduleScrollToEnd, scrollRef};
};
