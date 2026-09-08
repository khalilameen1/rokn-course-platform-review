import {Alert} from 'react-native';

import {selectRootTab} from '../journeyNavigation';
import type {RootNavigation} from '../types';

const navigation = () =>
  ({
    dispatch: jest.fn(),
    getState: jest.fn(() => ({
      index: 0,
      routes: [{key: 'home', name: 'Home'}],
    })),
    navigate: jest.fn(),
    reset: jest.fn(),
  } as unknown as RootNavigation);

describe('root guest navigation', () => {
  let clock = Date.now() + 10_000;

  beforeEach(() => {
    jest.spyOn(Date, 'now').mockImplementation(() => (clock += 1_000));
  });

  afterEach(() => jest.restoreAllMocks());

  it.each(['MyCorner', 'Wallet', 'Profile'] as const)(
    'keeps %s closed until the guest confirms login',
    target => {
      const nav = navigation();
      const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});

      selectRootTab(nav, target, false);

      expect(nav.navigate).not.toHaveBeenCalledWith(target);
      expect(alert).toHaveBeenCalledTimes(1);
      const buttons = alert.mock.calls[0][2];
      buttons?.[1]?.onPress?.();
      expect(nav.navigate).toHaveBeenCalledWith('Login', {
        returnTo: {name: target},
      });
    },
  );

  it('opens the selected private tab for an authenticated learner', () => {
    const nav = navigation();
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});

    selectRootTab(nav, 'Wallet', true);

    expect(alert).not.toHaveBeenCalled();
    expect(nav.navigate).toHaveBeenCalledWith('Wallet');
  });
});
