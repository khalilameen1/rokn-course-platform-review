import NetInfo from '@react-native-community/netinfo';
import {focusManager, onlineManager} from '@tanstack/react-query';
import {AppState, Platform, type AppStateStatus} from 'react-native';
import {isOnline} from '../playbackPreferenceConnectivity';

/**
 * TanStack Query v5 React Native guide, "Online status management" and
 * "Refetch on App focus": focus follows AppState and online status follows the
 * NetInfo connection the app already uses for its offline outboxes (same
 * `isOnline` rule, so a captive portal counts as offline everywhere).
 *
 * The guide sets focus from a component effect; `setEventListener` is the
 * manager API that effect wraps, which lets the root provider install and
 * remove both listeners together.
 */
export const installReactNativeQueryManagers = () => {
  focusManager.setEventListener(setFocused => {
    const subscription = AppState.addEventListener(
      'change',
      (status: AppStateStatus) => {
        if (Platform.OS !== 'web') setFocused(status === 'active');
      },
    );
    return () => subscription.remove();
  });
  onlineManager.setEventListener(setOnline =>
    NetInfo.addEventListener(state => setOnline(isOnline(state))),
  );
};
