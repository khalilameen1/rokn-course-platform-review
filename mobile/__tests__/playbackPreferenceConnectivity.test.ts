import {AppState} from 'react-native';

const mockSubscribe = jest.fn();
const mockCapture = jest.fn();
const mockFlush = jest.fn();
jest.mock('@react-native-community/netinfo', () => ({
  __esModule: true,
  default: {addEventListener: (...args: unknown[]) => mockSubscribe(...args)},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: () => mockCapture(),
}));
jest.mock('../src/services/playbackPreferenceSync', () => ({
  flushPlaybackPreferenceWrites: (...args: unknown[]) => mockFlush(...args),
}));

import {subscribeToPlaybackPreferenceRecovery} from '../src/services/playbackPreferenceConnectivity';

describe('native network recovery of durable playback preferences', () => {
  let listener: (state: {isConnected: boolean | null; isInternetReachable: boolean | null}) => void;
  let stop: (() => void) | undefined;
  let previousAppState: typeof AppState.currentState;
  const unsubscribe = jest.fn();
  const boundary = {scope: 'user-7', epoch: 4};
  const network = (connected: boolean | null, internet: boolean | null = connected) =>
    listener({isConnected: connected, isInternetReachable: internet});
  const settle = async () => {
    for (let tick = 0; tick < 8; tick += 1) await Promise.resolve();
  };

  beforeEach(() => {
    previousAppState = AppState.currentState;
    AppState.currentState = 'active';
    unsubscribe.mockClear();
    mockSubscribe.mockReset().mockImplementation(callback => {
      listener = callback;
      return unsubscribe;
    });
    mockCapture.mockReset().mockResolvedValue(boundary);
    mockFlush.mockReset().mockResolvedValue(undefined);
    stop = subscribeToPlaybackPreferenceRecovery();
  });
  afterEach(() => {
    stop?.();
    stop = undefined;
    AppState.currentState = previousAppState;
  });

  it('retries on restored internet while open without repeated flushes for unchanged online state', async () => {
    network(false);
    network(true);
    await settle();
    expect(mockFlush).toHaveBeenCalledWith(boundary, {afterCurrent: true});
    network(true);
    await settle();
    expect(mockFlush).toHaveBeenCalledTimes(1);
    network(true, false);
    network(true, true);
    await settle();
    expect(mockFlush).toHaveBeenCalledTimes(2);
  });

  it('does not treat connected Wi-Fi with known unreachable internet as recovered', async () => {
    network(null, null);
    network(true, false);
    await settle();
    expect(mockCapture).not.toHaveBeenCalled();
    network(true, null);
    await settle();
    expect(mockFlush).toHaveBeenCalledTimes(1);
  });

  it('leaves background recovery to the existing foreground lifecycle', async () => {
    AppState.currentState = 'background';
    network(false);
    network(true);
    await settle();
    expect(mockCapture).not.toHaveBeenCalled();
    expect(mockFlush).not.toHaveBeenCalled();
  });

  it('retires callbacks and a deferred account capture when its runtime unsubscribes', async () => {
    let finishCapture!: (value: typeof boundary) => void;
    mockCapture.mockReturnValue(new Promise(resolve => { finishCapture = resolve; }));
    network(true);
    stop?.();
    stop = undefined;
    finishCapture(boundary);
    network(false);
    network(true);
    await settle();
    expect(unsubscribe).toHaveBeenCalledTimes(1);
    expect(mockCapture).toHaveBeenCalledTimes(1);
    expect(mockFlush).not.toHaveBeenCalled();
  });

  it('captures the current account for a later reconnect rather than retaining its subscription-time account', async () => {
    network(true);
    await settle();
    const replacement = {scope: 'user-8', epoch: 5};
    mockCapture.mockResolvedValue(replacement);
    network(false);
    network(true);
    await settle();
    expect(mockFlush).toHaveBeenLastCalledWith(replacement, {afterCurrent: true});
  });
});
