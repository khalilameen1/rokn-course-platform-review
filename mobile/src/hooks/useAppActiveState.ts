import {useSyncExternalStore} from 'react';
import {
  AppState,
  Platform,
  type AppStateStatus,
  type NativeEventSubscription,
} from 'react-native';

let currentState: AppStateStatus = AppState.currentState;
let androidWindowFocused = true;
let nativeSubscriptions: NativeEventSubscription[] = [];
const listeners = new Set<() => void>();

const isForeground = () => currentState === 'active';
const isInteractive = () =>
  isForeground() && (Platform.OS !== 'android' || androidWindowFocused);

const notify = () => listeners.forEach(listener => listener());

const subscribe = (listener: () => void) => {
  listeners.add(listener);
  if (nativeSubscriptions.length === 0) {
    currentState = AppState.currentState;
    androidWindowFocused = currentState === 'active';
    nativeSubscriptions.push(
      AppState.addEventListener('change', next => {
        if (currentState === next) return;
        currentState = next;
        if (Platform.OS === 'android') {
          // Some Android versions do not emit a separate focus event after a
          // full background transition. The AppState transition is still an
          // authoritative reset; blur/focus then refine active-only overlays.
          androidWindowFocused = next === 'active';
        }
        notify();
      }),
    );
    if (Platform.OS === 'android') {
      // Android keeps AppState as `active` while the notification shade or
      // a native Modal takes focus. This gates playback, not foreground work
      // such as receiving a reply inside that Modal.
      nativeSubscriptions.push(
        AppState.addEventListener('blur', () => {
          if (!androidWindowFocused) return;
          androidWindowFocused = false;
          notify();
        }),
        AppState.addEventListener('focus', () => {
          if (androidWindowFocused) return;
          androidWindowFocused = true;
          notify();
        }),
      );
    }
  }
  return () => {
    listeners.delete(listener);
    if (listeners.size === 0 && nativeSubscriptions.length > 0) {
      nativeSubscriptions.forEach(subscription => subscription.remove());
      nativeSubscriptions = [];
    }
  };
};

export const useAppActiveState = () =>
  useSyncExternalStore(subscribe, isInteractive, () => true);

/** Foreground data work continues inside native dialogs and sheets. */
export const useAppForegroundState = () =>
  useSyncExternalStore(subscribe, isForeground, () => true);
