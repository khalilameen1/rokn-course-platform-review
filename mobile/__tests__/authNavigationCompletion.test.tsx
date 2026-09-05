import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {configureStore} from '@reduxjs/toolkit';
import {Provider} from 'react-redux';
import {createNavigationContainerRef} from '@react-navigation/native';
import authReducer, {saveLoginData} from '../src/store/reducers/auth';
import type {RootStackParamList} from '../src/navigation/types';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {savePendingLoginReturnTo} from '../src/navigation/authReturn';

const mockNavigationRef = createNavigationContainerRef<RootStackParamList>();
jest.mock('react-redux', () =>
  jest.requireActual(
    '../node_modules/react-redux/dist/cjs/react-redux.development.cjs',
  ),
);
jest.mock('../src/navigation/RootNavigationHelper', () => ({
  navigationRef: mockNavigationRef,
}));
jest.mock('../src/services/pushNotifications', () => ({
  flushPendingNotificationNavigation: async () => undefined,
  setNotificationNavigationReady: jest.fn(),
}));
jest.mock('../src/services/secureSession', () => ({
  sessionIdentityKey: (value: {user?: {id?: number}}) =>
    value?.user?.id ? `account-${value.user.id}` : 'guest',
}));
jest.mock('../src/constants/helpers', () => ({
  getCurrentAccountStorageScope: async () => 'guest-test',
  getCurrentGuestJourneyScope: async () => 'guest-test',
}));
jest.mock('../src/navigation/checkoutReturn', () => ({
  claimPendingCheckoutReturn: async () => undefined,
}));
jest.mock('../src/navigation/roknLinking', () => ({
  roknLinking: undefined,
  getInitialAppUrl: async () => null,
  isRoknNavigationReady: () => true,
  resetRoknLinking: jest.fn(),
  markRoknNavigationReady: jest.fn(),
  flushLateInitialDestination: jest.fn(),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
// Keep the real navigation builder/router/container and production Navigation,
// replacing only the native screen host that is unavailable in Jest.
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

for (const screen of [
  'Reels',
  'Home',
  'Login',
  'CourseDetails',
  'MyCorner',
  'Wallet',
  'Profile',
  'Settings',
  'Informations/AboutUs',
  'Informations/PrivacyPolicy',
  'Informations/TermsOfUse',
  'Notifications',
  'EditAccount',
  'Feedback',
  'DeviceSessions',
]) {
  jest.doMock(`../src/screens/${screen}`, () => () => null);
}
const Navigation = require('../src/navigation/Navigation').default;

describe('completed login navigation', () => {
  beforeEach(async () => {
    await AsyncStorage.clear();
  });

  it.each(['none', 'durable', 'route-only'] as const)(
    'leaves Login after session adoption with a %s return and no second provider tap',
    async returnKind => {
      const store = configureStore({reducer: {auth: authReducer}});
      const returnTo = {
        name: 'CourseDetails' as const,
        params: {courseId: '3', openPurchase: true, purchasePlanCode: 'mentor'},
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        await act(async () => {
          renderer = TestRenderer.create(
            <Provider store={store}>
              <Navigation />
            </Provider>,
          );
        });
        expect(mockNavigationRef.getCurrentRoute()?.name).toBe('Home');
        await act(async () => {
          mockNavigationRef.navigate(
            'Login',
            returnKind === 'route-only' ? {returnTo} : {},
          );
        });
        expect(mockNavigationRef.getCurrentRoute()?.name).toBe('Login');
        if (returnKind === 'durable') await savePendingLoginReturnTo(returnTo);
        await act(async () => {
          store.dispatch(
            saveLoginData({api_token: 'completed-token', user: {id: 52}}),
          );
        });
        expect(store.getState().auth.isLogin).toBe(true);
        expect(mockNavigationRef.getCurrentRoute()?.name).toBe(
          returnKind === 'none' ? 'Home' : 'CourseDetails',
        );
        if (returnKind !== 'none')
          expect(mockNavigationRef.getCurrentRoute()?.params).toMatchObject(
            returnTo.params,
          );
      } finally {
        if (renderer) await act(async () => renderer.unmount());
      }
    },
  );
});
