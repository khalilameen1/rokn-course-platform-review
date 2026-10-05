import NetInfo from '@react-native-community/netinfo';
import {AppState} from 'react-native';
import {captureAccountSessionBoundary} from '../constants/helpers';
import {flushPlaybackPreferenceWrites} from './playbackPreferenceSync';

/** Native connectivity recovery, not a timer or a second preference writer. */
export const subscribeToPlaybackPreferenceRecovery = () => {
  let active = true;
  let reachable = false;
  const unsubscribe = NetInfo.addEventListener(state => {
    if (!active) return;
    const connected =
      state.isConnected === true && state.isInternetReachable !== false;
    const restored = connected && !reachable;
    reachable = connected;
    if (!restored || AppState.currentState !== 'active') return;
    void captureAccountSessionBoundary()
      .then(boundary => {
        if (active && AppState.currentState === 'active')
          return flushPlaybackPreferenceWrites(boundary, {afterCurrent: true});
      })
      .catch(() => undefined);
  });
  return () => {
    active = false;
    unsubscribe();
  };
};
