import type {LoginReturnTo, RootNavigation} from './types';

let loginGateUntil = 0;

type GuestLoginNavigation = Pick<RootNavigation, 'getState' | 'navigate'>;

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
