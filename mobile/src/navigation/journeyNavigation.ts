import {StackActions} from '@react-navigation/native';
import type {LoginReturnTo, RootNavigation} from './types';

export type RootTabName = 'Home' | 'MyCorner' | 'Wallet' | 'Profile';

let loginGateUntil = 0;

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
