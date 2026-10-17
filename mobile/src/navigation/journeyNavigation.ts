import {StackActions} from '@react-navigation/native';
import type {LoginReturnTo, RootNavigation} from './types';

export type RootTabName = 'Home' | 'MyCorner' | 'Wallet' | 'Profile';

let loginGateUntil = 0;

/**
 * Tab-bar switches are not forward navigation. Bottom navigation switches
 * destinations in place without a push transition (Material 3 navigation bar,
 * iOS tab bars, React Navigation bottom tabs). The app's roots live in one
 * native stack, so a tab switch marks the route it creates and the navigator
 * renders that route with `animation: 'none'` (its pop/replace too).
 */
const TAB_SWITCH_WINDOW_MS = 1_000;
const MAX_TRACKED_ROUTES = 32;
let pendingTabSwitch: {name: RootTabName; until: number} | null = null;
const tabSwitchRoutes = new Map<string, boolean>();

const markTabSwitch = (name: RootTabName) => {
  pendingTabSwitch = {name, until: Date.now() + TAB_SWITCH_WINDOW_MS};
};

/** Whether this root route was entered from the tab bar (decided once per route). */
export const isTabSwitchRoute = (route: {key: string; name: string}) => {
  const known = tabSwitchRoutes.get(route.key);
  if (known !== undefined) return known;
  const pending = pendingTabSwitch;
  const fromTabBar =
    pending !== null &&
    pending.name === route.name &&
    Date.now() <= pending.until;
  if (fromTabBar) pendingTabSwitch = null;
  tabSwitchRoutes.set(route.key, fromTabBar);
  if (tabSwitchRoutes.size > MAX_TRACKED_ROUTES) {
    const oldest = tabSwitchRoutes.keys().next().value;
    if (oldest !== undefined) tabSwitchRoutes.delete(oldest);
  }
  return fromTabBar;
};

type GuestLoginNavigation = Pick<RootNavigation, 'getState' | 'navigate'>;

/**
 * Bottom navigation is one top-level journey, not a stack of copies. Keep the
 * mounted Home screen underneath so switching tabs does not lose its scroll,
 * search or last-known-good catalogue.
 */
export const selectRootTab = (
  navigation: RootNavigation,
  target: RootTabName,
  authenticated: boolean,
) => {
  const state = navigation.getState();
  const current = state.routes[state.index]?.name;
  if (current === target) return;

  if (target !== 'Home' && !authenticated) {
    openGuestLogin(navigation, {name: target});
    return;
  }

  const hasHome = state.routes.some(route => route.name === 'Home');
  if (target === 'Home') {
    if (hasHome) navigation.dispatch(StackActions.popTo('Home'));
    else navigation.reset({index: 0, routes: [{name: 'Home'}]});
    return;
  }

  markTabSwitch(target);
  if (current === 'Home') {
    navigation.navigate(target);
    return;
  }

  // TabBar is rendered only on these four roots. Replacing the current root
  // preserves Home underneath and prevents Home → Wallet → Profile piles.
  navigation.dispatch(StackActions.replace(target));
};

/** One physical tap must create at most one Login screen during transition. */
export const openGuestLogin = (
  navigation: GuestLoginNavigation,
  returnTo?: LoginReturnTo,
) => {
  const state = navigation.getState();
  if (state.routes[state.index]?.name === 'Login') return;
  const now = Date.now();
  if (now < loginGateUntil) return;
  loginGateUntil = now + 750;
  navigation.navigate('Login', returnTo ? {returnTo} : undefined);
};
