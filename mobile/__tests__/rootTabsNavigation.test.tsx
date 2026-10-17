import React from 'react';
import {BackHandler} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {configureStore} from '@reduxjs/toolkit';
import {Provider} from 'react-redux';
import AsyncStorage from '@react-native-async-storage/async-storage';
import authReducer, {saveLoginData} from '../src/store/reducers/auth';
import type {RootNavigation} from '../src/navigation/types';

let mockInitialUrl: string | null = null;
const mockMounts: Record<string, number> = {};
const mockUnmounts: Record<string, number> = {};
const mockParams: Record<string, unknown> = {};

jest.mock('react-redux', () =>
  jest.requireActual(
    '../node_modules/react-redux/dist/cjs/react-redux.development.cjs',
  ),
);
jest.mock('../src/services/pushNotificationNavigation', () => ({
  flushPendingNotificationNavigation: async () => undefined,
  setNotificationNavigationReady: jest.fn(),
}));
jest.mock('../src/services/secureSession', () => ({
  sessionIdentityKey: (value: {user?: {id?: number}}) =>
    value?.user?.id ? `account-${value.user.id}` : 'guest',
}));
jest.mock('../src/constants/helpers', () => ({
  extractApiToken: (value: {api_token?: string}) => value?.api_token || '',
  getCurrentAccountStorageScope: async () => 'guest-test',
  getCurrentGuestJourneyScope: async () => 'guest-test',
}));
jest.mock('../src/navigation/checkoutReturn', () => ({
  claimPendingCheckoutReturn: async () => undefined,
}));
// Real deep-link parsing and React Navigation path config; only the native
// URL source is replaced by the URL each test opens the app with.
jest.mock('../src/navigation/roknLinking', () => {
  const actual = jest.requireActual('../src/navigation/roknLinking');
  return {
    ...actual,
    roknLinking: {
      ...actual.roknLinking,
      getInitialURL: async () => mockInitialUrl,
      subscribe: () => () => undefined,
    },
    getInitialAppUrl: async () => null,
    isRoknNavigationReady: () => true,
    resetRoknLinking: jest.fn(),
    markRoknNavigationReady: jest.fn(),
    flushLateInitialDestination: jest.fn(),
  };
});
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
// Keep the real router/container and the real bottom-tab navigator; replace
// only the native stack host, which is unavailable in Jest.
jest.mock('@react-navigation/native-stack', () => {
  const ReactModule = require('react');
  const {
    createNavigatorFactory,
    useNavigationBuilder,
    StackRouter,
  } = require('@react-navigation/native');
  const Navigator = (props: any) => {
    const {state, descriptors, NavigationContent} = useNavigationBuilder(
      StackRouter,
      props,
    );
    return ReactModule.createElement(
      NavigationContent,
      null,
      descriptors[state.routes[state.index].key].render(),
    );
  };
  return {createNativeStackNavigator: createNavigatorFactory(Navigator)};
});

const tabScreen = (name: string) => () => {
  const ReactModule = require('react');
  const {useRoute} = require('@react-navigation/native');
  return function MockTabScreen() {
    const route = useRoute();
    mockParams[name] = route.params;
    ReactModule.useEffect(() => {
      mockMounts[name] = (mockMounts[name] || 0) + 1;
      return () => {
        mockUnmounts[name] = (mockUnmounts[name] || 0) + 1;
      };
    }, []);
    return null;
  };
};
for (const screen of ['Home', 'MyCorner', 'Wallet', 'Profile']) {
  jest.doMock(`../src/screens/${screen}`, tabScreen(screen));
}
for (const screen of [
  'Reels',
  'Login',
  'CourseDetails',
  'CourseCertificate',
  'Settings',
  'Informations/AboutUs',
  'Informations/PrivacyPolicy',
  'Informations/TermsOfUse',
  'Notifications',
  'EditAccount',
  'Feedback',
  'DeviceSessions',
  'SavedLibrary',
]) {
  jest.doMock(`../src/screens/${screen}`, () => () => null);
}
const Navigation = require('../src/navigation/Navigation').default;
const {
  navigationRef,
  openRoknDestination,
} = require('../src/navigation/RootNavigationHelper');
const {openRootTab} = require('../src/navigation/rootRoutes');

const signedIn = () => {
  const store = configureStore({reducer: {auth: authReducer}});
  store.dispatch(saveLoginData({api_token: 'tabs-token', user: {id: 7}}));
  return store;
};

const currentRoute = () => navigationRef.getCurrentRoute();
const rootRoutes = () =>
  navigationRef
    .getRootState()
    .routes.map((route: {name: string}) => route.name);

describe('root bottom tabs', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let hardwareBack: (() => boolean | null | undefined) | undefined;

  const open = async (store = signedIn()) => {
    await act(async () => {
      renderer = TestRenderer.create(
        <Provider store={store}>
          <Navigation sessionReady />
        </Provider>,
      );
    });
    return store;
  };
  const tabs = () =>
    renderer!.root.findAll(
      node =>
        node.props.accessibilityRole === 'tab' &&
        typeof node.props.onPress === 'function' &&
        typeof node.type !== 'string',
      {deep: false},
    );
  const tab = (label: string) =>
    tabs().find(node => node.props.accessibilityLabel === label)!;
  const pressTab = async (label: string) => {
    await act(async () => tab(label).props.onPress());
  };

  beforeEach(async () => {
    await AsyncStorage.clear();
    mockInitialUrl = null;
    for (const record of [mockMounts, mockUnmounts, mockParams]) {
      for (const key of Object.keys(record)) delete record[key];
    }
    hardwareBack = undefined;
    jest
      .spyOn(BackHandler, 'addEventListener')
      .mockImplementation((_event, handler) => {
        hardwareBack = handler;
        return {remove: jest.fn()};
      });
  });

  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    jest.restoreAllMocks();
  });

  it('renders one RTL tab bar with the approved order and selected state', async () => {
    await open();
    expect(tabs().map(node => node.props.accessibilityLabel)).toEqual([
      'Home',
      'My Corner',
      'Wallet',
      'Me',
    ]);
    expect(tabs().map(node => node.props.accessibilityState)).toEqual([
      {selected: true},
      {selected: false},
      {selected: false},
      {selected: false},
    ]);
    await pressTab('Wallet');
    expect(tab('Wallet').props.accessibilityState).toEqual({selected: true});
    expect(tab('Home').props.accessibilityState).toEqual({selected: false});
  });

  it('keeps every visited tab mounted across switches', async () => {
    await open();
    expect(currentRoute()?.name).toBe('Home');
    // Lazy: an unvisited tab is not mounted.
    expect(mockMounts).toEqual({Home: 1});

    await pressTab('Wallet');
    await pressTab('My Corner');
    await pressTab('Wallet');
    await pressTab('Me');
    await pressTab('Home');
    await pressTab('My Corner');

    expect(currentRoute()?.name).toBe('MyCorner');
    expect(mockMounts).toEqual({Home: 1, Wallet: 1, MyCorner: 1, Profile: 1});
    expect(mockUnmounts).toEqual({});
    // Tab switches never push or replace root-stack screens.
    expect(rootRoutes()).toEqual(['Tabs']);
  });

  it('opens guest login from a private tab and returns to it after login', async () => {
    const store = await open(configureStore({reducer: {auth: authReducer}}));
    const tabsKey = navigationRef.getRootState().routes[0].key;

    await pressTab('Wallet');

    // The tabPress listener opened Login over the same tab navigator; a
    // focused private tab would have been reset away by its boundary.
    expect(rootRoutes()).toEqual(['Tabs', 'Login']);
    expect(navigationRef.getRootState().routes[0].key).toBe(tabsKey);
    expect(currentRoute()?.name).toBe('Login');
    expect(currentRoute()?.params).toEqual({returnTo: {name: 'Wallet'}});
    expect(mockMounts.Wallet).toBeUndefined();

    await act(async () => {
      store.dispatch(saveLoginData({api_token: 'guest-now-in', user: {id: 9}}));
    });

    expect(currentRoute()?.name).toBe('Wallet');
    expect(rootRoutes()).toEqual(['Tabs']);
  });

  it.each([
    ['rokn://wallet', 'Wallet', undefined],
    ['https://rokn.app/profile/certificates', 'Profile', {tab: 'certificates'}],
  ])(
    'lands a cold-start link %s in its tab with Home behind it',
    async (url, name, params) => {
      mockInitialUrl = url;
      await open();

      expect(currentRoute()?.name).toBe(name);
      expect(mockParams[name]).toEqual(params);
      expect(rootRoutes()).toEqual(['Tabs']);

      let handled: boolean | null | undefined;
      act(() => {
        handled = hardwareBack?.();
      });
      expect(handled).toBe(true);
      expect(currentRoute()?.name).toBe('Home');
    },
  );

  it('sends Android Back from any tab to Home, then lets the app exit', async () => {
    await open();
    await pressTab('My Corner');
    await pressTab('Wallet');

    let handled: boolean | null | undefined;
    act(() => {
      handled = hardwareBack?.();
    });
    expect(handled).toBe(true);
    expect(currentRoute()?.name).toBe('Home');

    act(() => {
      handled = hardwareBack?.();
    });
    expect(handled).toBe(false);
    expect(currentRoute()?.name).toBe('Home');
  });

  it('opens a tab with params from a pushed screen without a second tab navigator', async () => {
    await open();
    await act(async () => {
      navigationRef.navigate('CourseDetails', {courseId: '12'});
    });
    expect(rootRoutes()).toEqual(['Tabs', 'CourseDetails']);

    await act(async () => {
      openRootTab(navigationRef as unknown as RootNavigation, 'Profile', {
        tab: 'portfolio',
        addPortfolioProjectId: '55',
      });
    });

    expect(currentRoute()?.name).toBe('Profile');
    expect(mockParams.Profile).toEqual({
      tab: 'portfolio',
      addPortfolioProjectId: '55',
    });
    expect(rootRoutes()).toEqual(['Tabs']);
    expect(mockMounts.Home).toBe(1);

    await act(async () => {
      openRootTab(navigationRef as unknown as RootNavigation, 'Wallet');
    });
    expect(currentRoute()?.name).toBe('Wallet');
  });

  it('opens a warm notification or app link in its tab', async () => {
    await open();
    await act(async () => {
      openRoknDestination({name: 'Profile', params: {tab: 'certificates'}});
    });
    expect(currentRoute()?.name).toBe('Profile');
    expect(mockParams.Profile).toEqual({tab: 'certificates'});

    await act(async () => {
      openRoknDestination({name: 'CourseDetails', params: {courseId: '4'}});
    });
    expect(rootRoutes()).toEqual(['Tabs', 'CourseDetails']);
  });
});
