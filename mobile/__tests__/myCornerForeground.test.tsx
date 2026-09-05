import React from 'react';
import {AppState, Platform} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetDashboard = jest.fn();
jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void) => {
    const ReactModule = require('react');
    ReactModule.useEffect(effect, [effect]);
  },
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'learner', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: async () => true,
  getCachedLearningDashboard: async () => null,
  getLearningDashboard: () => mockGetDashboard(),
}));
import {useMyCornerData} from '../src/screens/myCorner/useMyCornerData';

describe('MyCorner foreground data ownership', () => {
  it('accepts an in-flight dashboard behind a native modal without refetching on window focus', async () => {
    const previousPlatform = Platform.OS;
    const previousState = AppState.currentState;
    Platform.OS = 'android';
    AppState.currentState = 'active';
    const listeners = new Map<string, (...args: any[]) => void>();
    const subscription = jest
      .spyOn(AppState, 'addEventListener')
      .mockImplementation((event, listener) => {
        listeners.set(event, listener);
        return {
          remove: () => {
            listeners.delete(event);
          },
        };
      });
    let finish!: (value: unknown) => void;
    mockGetDashboard.mockReset().mockImplementation(
      () =>
        new Promise(resolve => {
          finish = resolve;
        }),
    );
    let latest!: ReturnType<typeof useMyCornerData>;
    const Harness = () => {
      latest = useMyCornerData('learner');
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      expect(mockGetDashboard).toHaveBeenCalledTimes(1);
      await act(async () => {
        listeners.get('blur')?.();
      });
      const dashboard = {
        courses: [{id: '3', title: 'كورس الطالب'}],
        paths: [],
        badges: [],
        activityDays: [],
        currentStreakDays: 0,
      };
      await act(async () => {
        finish(dashboard);
      });
      expect(latest.dashboard).toBe(dashboard);
      expect(latest.learningOwnershipFresh).toBe(true);
      await act(async () => {
        listeners.get('focus')?.();
      });
      expect(mockGetDashboard).toHaveBeenCalledTimes(1);
      await act(async () => {
        AppState.currentState = 'background';
        listeners.get('change')?.('background');
      });
      await act(async () => {
        AppState.currentState = 'active';
        listeners.get('change')?.('active');
      });
      expect(mockGetDashboard).toHaveBeenCalledTimes(2);
    } finally {
      if (renderer) await act(async () => renderer.unmount());
      subscription.mockRestore();
      Platform.OS = previousPlatform;
      AppState.currentState = previousState;
    }
  });
});
