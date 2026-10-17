import NetInfo, {type NetInfoState} from '@react-native-community/netinfo';
import {AppState} from 'react-native';

/** NetInfo reports several states while Wi-Fi and cellular hand over. */
export const CONNECTIVITY_RECOVERY_DEBOUNCE_MS = 1_500;

const isOnline = (state: Pick<NetInfoState, 'isConnected' | 'isInternetReachable'>) =>
  // `isInternetReachable` is null until NetInfo has probed; only a known
  // unreachable internet (captive portal, dead Wi-Fi) counts as offline.
  state.isConnected === true && state.isInternetReachable !== false;

/**
 * Native connectivity recovery for durable offline work (pending account
 * writes, playback preferences, telemetry/analytics outboxes, learning
 * progress). Same model as TanStack Query's React Native `onlineManager`
 * recipe (`NetInfo.addEventListener(state => setOnline(!!state.isConnected))`):
 * the state starts online and only an offline -> online edge resumes paused
 * work. Startup and foreground replays stay owned by the app lifecycle.
 *
 * The callback runs once per restored connection, after the network has been
 * stable for `debounceMs`, and only while the app is in the foreground.
 */
export const subscribeToConnectivityRecovery = (
  onRestored: () => void,
  {debounceMs = CONNECTIVITY_RECOVERY_DEBOUNCE_MS}: {debounceMs?: number} = {},
) => {
  let active = true;
  let online = true;
  let timer: ReturnType<typeof setTimeout> | null = null;
  const cancelPending = () => {
    if (timer) clearTimeout(timer);
    timer = null;
  };
  const unsubscribe = NetInfo.addEventListener(state => {
    if (!active) return;
    const next = isOnline(state);
    const restored = next && !online;
    online = next;
    if (!next) {
      cancelPending();
      return;
    }
    if (!restored) return;
    cancelPending();
    timer = setTimeout(() => {
      timer = null;
      if (active && online && AppState.currentState === 'active') onRestored();
    }, Math.max(0, debounceMs));
  });
  return () => {
    active = false;
    cancelPending();
    unsubscribe();
  };
};
