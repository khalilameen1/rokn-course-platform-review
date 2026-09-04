import {
  CommonActions,
  createNavigationContainerRef,
} from '@react-navigation/native';
import {safeLoginReturnToFromRoute} from './authReturn';
import type {RoknDestination} from './deepLinks';
import type {LoginReturnTo, RootNavigation, RootStackParamList} from './types';

type NavigationParams = Record<string, unknown> | undefined;

export const navigationRef = createNavigationContainerRef<RootStackParamList>();

const sameLinkableDestination = (
  name: keyof RootStackParamList,
  params?: NavigationParams,
) => {
  if (!navigationRef.isReady()) return false;
  const current = navigationRef.getCurrentRoute();
  if (current?.name !== name) return false;
  if (name === 'Home' || name === 'Wallet') return true;
  if (name === 'Profile') {
    const currentParams = (current.params || {}) as Record<string, unknown>;
    return String(currentParams.tab || '') === String(params?.tab || '');
  }
  if (name === 'Feedback') {
    const currentParams = (current.params || {}) as Record<string, unknown>;
    return String(currentParams.caseId || '') === String(params?.caseId || '');
  }
  if (name !== 'CourseDetails' && name !== 'Reels') return false;

  const currentParams = (current.params || {}) as Record<string, unknown>;
  const nextParams = params || {};
  return (
    String(currentParams.courseId || '') === String(nextParams.courseId || '') &&
    String(currentParams.reelId || '') === String(nextParams.reelId || '') &&
    String(currentParams.lessonId || '') === String(nextParams.lessonId || '') &&
    String(currentParams.projectId || '') === String(nextParams.projectId || '')
  );
};

export function navigate(
  name: keyof RootStackParamList,
  params?: NavigationParams,
) {
  if (!navigationRef.isReady()) return false;
  // Android can deliver one tap through both the activity intent and the push
  // response after a cold start. Treat an already-open canonical destination
  // as success instead of stacking an indistinguishable second screen.
  if (sameLinkableDestination(name, params)) return true;
  navigationRef.dispatch(CommonActions.navigate(name, params));
  return true;
}

/**
 * External intents start a clean top-level journey. This prevents a browser or
 * notification tap from leaving a stale purchase sheet or modal underneath
 * the destination and resurrecting it on Back.
 */
export function openRoknDestination(destination: RoknDestination) {
  const params = 'params' in destination ? destination.params : undefined;
  if (!navigationRef.isReady()) return false;
  if (sameLinkableDestination(destination.name, params)) {
    // Re-delivery of the destination already on screen is a no-op regardless
    // of timing. Resetting it after the short native dedupe window discarded
    // scroll, selected tabs and open learner context without changing route.
    return true;
  }
  navigationRef.dispatch(
    CommonActions.reset({
      index: destination.name === 'Home' ? 0 : 1,
      routes:
        destination.name === 'Home'
          ? [{name: 'Home'}]
          : [
              {name: 'Home'},
              params
                ? {name: destination.name, params}
                : {name: destination.name},
            ],
    }),
  );
  return true;
}

export function getLoginReturnToSnapshot(): LoginReturnTo | undefined {
  if (!navigationRef.isReady()) return undefined;
  return safeLoginReturnToFromRoute(navigationRef.getCurrentRoute());
}

export function goBack() {
  if (navigationRef.isReady() && navigationRef.canGoBack()) {
    navigationRef.goBack();
  }
}

export function goBackOrHome(
  navigation: Pick<RootNavigation, 'canGoBack' | 'goBack' | 'reset'>,
) {
  if (navigation.canGoBack()) {
    navigation.goBack();
    return;
  }
  navigation.reset({index: 0, routes: [{name: 'Home'}]});
}

export function reset(
  index: number,
  routes: Array<{name: string; params?: NavigationParams}>,
) {
  if (!navigationRef.isReady()) return;
  navigationRef.dispatch(CommonActions.reset({index, routes}));
}

type NestedRoute = {
  name?: string;
  params?: {screen?: string};
  state?: {
    index: number;
    routes: NestedRoute[];
  };
};

export const getPreviousRouteFromState = (
  route: NestedRoute,
): NestedRoute | null => {
  const state = route.state;
  if (!state || state.index < 0 || !state.routes.length) return null;

  const activeRoute = state.routes[state.index];
  if (activeRoute?.state?.routes?.length) {
    return getPreviousRouteFromState(activeRoute);
  }

  return state.routes[state.index - 1] ?? activeRoute ?? null;
};

export const getActiveRouteName = (route: NestedRoute): string => {
  const state = route.state;
  if (state?.routes?.length) {
    return state.routes[state.index]?.name ?? 'Home';
  }
  return route.params?.screen ?? route.name ?? 'Home';
};
