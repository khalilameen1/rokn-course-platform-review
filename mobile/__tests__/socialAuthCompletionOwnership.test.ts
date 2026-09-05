import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';

const mockPost = jest.fn();
const mockWelcomeReceipt = jest.fn();
jest.mock('../src/constants/api', () => ({
  publicRequest: {post: (...args: unknown[]) => mockPost(...args)},
}));
jest.mock('../src/services/installationIdentity', () => ({
  getRequiredInstallationId: async () => '11111111-1111-4111-8111-111111111111',
}));
jest.mock('../src/services/pendingWelcomeBonus', () => ({
  savePendingWelcomeBonus: (...args: unknown[]) => mockWelcomeReceipt(...args),
}));

import {resumePendingSocialAuth} from '../src/services/socialAuthCompletion';
import {
  loadSecureSession,
  peekSecureSession,
  resetSecureSessionForTests,
  savePendingSocialAuthAttempt,
} from '../src/services/secureSession';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../src/constants/helpers';

describe('social login completion ownership', () => {
  it('joins a completed journal observed during the live exchange without invalidating the first account read', async () => {
    const secureValues = new Map<string, string>();
    resetSecureSessionForTests();
    await AsyncStorage.clear();
    (SecureStore.getItemAsync as jest.Mock).mockImplementation(
      async key => secureValues.get(key) ?? null,
    );
    (SecureStore.setItemAsync as jest.Mock).mockImplementation(
      async (key, value) => {
        secureValues.set(key, value);
      },
    );
    (SecureStore.deleteItemAsync as jest.Mock).mockImplementation(async key => {
      secureValues.delete(key);
    });
    await loadSecureSession();
    const pending = {
      provider: 'google' as const,
      verifier: 'V'.repeat(64),
      challenge: 'C'.repeat(43),
      flow: 'browser' as const,
      startedAt: new Date().toISOString(),
    };
    await savePendingSocialAuthAttempt(pending);
    const session = {
      api_token: 'same-completed-bearer',
      user: {id: 52, name: 'Learner', social_provider: 'google'},
    };
    mockPost.mockResolvedValue({
      data: {status: 200, success: true, data: session},
    });
    let releaseReceipt!: () => void;
    let receiptStarted!: () => void;
    const receiptReady = new Promise<void>(resolve => {
      receiptStarted = resolve;
    });
    const receipt = new Promise<void>(resolve => {
      releaseReceipt = resolve;
    });
    mockWelcomeReceipt
      .mockImplementationOnce(() => {
        receiptStarted();
        return receipt;
      })
      .mockResolvedValue(undefined);
    const live = resumePendingSocialAuth(
      `rokn://auth?attempt=${pending.challenge}&code=one-time-code`,
    );
    let observer: ReturnType<typeof resumePendingSocialAuth> | undefined;
    try {
      await receiptReady;
      const firstReadOwner = await captureAccountSessionBoundary();
      const committedEpoch = peekSecureSession().epoch;
      observer = resumePendingSocialAuth();
      await new Promise<void>(resolve => setImmediate(resolve));
      expect(peekSecureSession().epoch).toBe(committedEpoch);
      expect(() => assertAccountSessionBoundary(firstReadOwner)).not.toThrow();
      expect(mockPost).toHaveBeenCalledTimes(1);
    } finally {
      releaseReceipt();
      await Promise.all([live, observer]);
    }
  });
});
