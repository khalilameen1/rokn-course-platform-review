import {AppState} from 'react-native';

const mockSubscribe = jest.fn();
jest.mock('@react-native-community/netinfo', () => ({
  __esModule: true,
  default: {addEventListener: (...args: unknown[]) => mockSubscribe(...args)},
}));

import {
  CONNECTIVITY_RECOVERY_DEBOUNCE_MS,
  subscribeToConnectivityRecovery,
} from '../src/services/playbackPreferenceConnectivity';

describe('native connectivity recovery of durable offline work', () => {
  let listener: (state: {
    isConnected: boolean | null;
    isInternetReachable: boolean | null;
  }) => void;
  let stop: (() => void) | undefined;
  let previousAppState: typeof AppState.currentState;
  const unsubscribe = jest.fn();
  const onRestored = jest.fn();
  const network = (
    connected: boolean | null,
    internet: boolean | null = connected,
  ) => listener({isConnected: connected, isInternetReachable: internet});
  const settle = () => jest.advanceTimersByTime(CONNECTIVITY_RECOVERY_DEBOUNCE_MS);

  beforeEach(() => {
    jest.useFakeTimers();
    previousAppState = AppState.currentState;
    AppState.currentState = 'active';
    unsubscribe.mockClear();
    onRestored.mockClear();
    mockSubscribe.mockReset().mockImplementation(callback => {
      listener = callback;
      return unsubscribe;
    });
    stop = subscribeToConnectivityRecovery(onRestored);
  });
  afterEach(() => {
    stop?.();
    stop = undefined;
    AppState.currentState = previousAppState;
    jest.useRealTimers();
  });

  it('replays once per offline -> online edge, not for unchanged online state', () => {
    network(true);
    settle();
    expect(onRestored).not.toHaveBeenCalled();
    network(false);
    network(true);
    settle();
    expect(onRestored).toHaveBeenCalledTimes(1);
    network(true);
    settle();
    expect(onRestored).toHaveBeenCalledTimes(1);
    network(true, false);
    network(true, true);
    settle();
    expect(onRestored).toHaveBeenCalledTimes(2);
  });

  it('debounces a flapping hand-off into one replay after the network settles', () => {
    network(false);
    network(true);
    jest.advanceTimersByTime(CONNECTIVITY_RECOVERY_DEBOUNCE_MS - 1);
    network(false);
    network(true);
    jest.advanceTimersByTime(CONNECTIVITY_RECOVERY_DEBOUNCE_MS - 1);
    expect(onRestored).not.toHaveBeenCalled();
    jest.advanceTimersByTime(1);
    expect(onRestored).toHaveBeenCalledTimes(1);
  });

  it('cancels a scheduled replay when the network drops again', () => {
    network(false);
    network(true);
    network(false);
    settle();
    expect(onRestored).not.toHaveBeenCalled();
  });

  it('does not treat connected Wi-Fi with known unreachable internet as recovered', () => {
    network(null, null);
    network(true, false);
    settle();
    expect(onRestored).not.toHaveBeenCalled();
    network(true, null);
    settle();
    expect(onRestored).toHaveBeenCalledTimes(1);
  });

  it('leaves background recovery to the existing foreground lifecycle', () => {
    AppState.currentState = 'background';
    network(false);
    network(true);
    settle();
    expect(onRestored).not.toHaveBeenCalled();
  });

  it('retires callbacks and a pending replay when its runtime unsubscribes', () => {
    network(false);
    network(true);
    stop?.();
    stop = undefined;
    settle();
    network(false);
    network(true);
    settle();
    expect(unsubscribe).toHaveBeenCalledTimes(1);
    expect(onRestored).not.toHaveBeenCalled();
  });
});
