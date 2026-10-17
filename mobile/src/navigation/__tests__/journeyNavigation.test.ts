import {Alert} from 'react-native';

import {openGuestLogin} from '../journeyNavigation';
import type {RootNavigation} from '../types';

const navigation = () =>
  ({
    getState: jest.fn(() => ({
      index: 0,
      routes: [{key: 'tabs', name: 'Tabs'}],
    })),
    navigate: jest.fn(),
  } as unknown as RootNavigation);

describe('guest login entry', () => {
  let clock = Date.now() + 10_000;

  beforeEach(() => {
    jest.spyOn(Date, 'now').mockImplementation(() => (clock += 1_000));
  });

  afterEach(() => jest.restoreAllMocks());

  it.each(['MyCorner', 'Wallet', 'Profile'] as const)(
    'opens the login sheet with a return to %s and no alert',
    target => {
      const nav = navigation();
      const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});

      openGuestLogin(nav, {name: target});

      expect(alert).not.toHaveBeenCalled();
      expect(nav.navigate).toHaveBeenCalledWith('Login', {
        returnTo: {name: target},
      });
    },
  );

  it('does not stack a second sheet when Login is already current', () => {
    const nav = navigation();
    jest.spyOn(nav, 'getState').mockReturnValue({
      index: 1,
      routes: [{name: 'Tabs'}, {name: 'Login'}],
    } as ReturnType<RootNavigation['getState']>);

    openGuestLogin(nav, {name: 'Wallet'});

    expect(nav.navigate).not.toHaveBeenCalled();
  });

  it('turns a double tap into one Login screen', () => {
    const nav = navigation();
    jest.spyOn(Date, 'now').mockReturnValue(clock + 50_000);

    openGuestLogin(nav, {name: 'Wallet'});
    openGuestLogin(nav, {name: 'Wallet'});

    expect(nav.navigate).toHaveBeenCalledTimes(1);
  });
});
