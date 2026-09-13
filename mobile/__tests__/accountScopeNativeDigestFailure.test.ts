import {createHash} from 'crypto';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Crypto from 'expo-crypto';

let mockSnapshot: {ready: boolean; session: unknown; epoch: number};

jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
  digestStringAsync: jest.fn(async () => {
    throw new Error('NATIVE_DIGEST_UNAVAILABLE');
  }),
  CryptoDigestAlgorithm: {SHA256: 'SHA-256'},
}));
jest.mock('../src/services/secureSession', () => ({
  ...jest.requireActual('../src/services/secureSession'),
  peekSecureSession: () => mockSnapshot,
}));

import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../src/constants/helpers';

const legacyHash = (value: string) =>
  createHash('sha256').update(value, 'utf8').digest('hex').slice(0, 24);

describe('account scope without native digest availability', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    mockSnapshot = {
      ready: true,
      session: {user: {id: 6}, api_token: 'test-session'},
      epoch: 4,
    };
  });

  it('prepares private reads with the existing cache key when native hashing fails', async () => {
    const boundary = await captureAccountSessionBoundary();
    expect(boundary).toEqual({epoch: 4, scope: `user-${legacyHash('6')}`});
    await expect(accountScopedStorageKey('courses', boundary)).resolves.toBe(
      `courses:user-${legacyHash('6')}`,
    );
    expect(Crypto.digestStringAsync).not.toHaveBeenCalled();
  });

  it('preserves guest keys and does not share them with an authenticated account', async () => {
    const guestId = '11111111-1111-4111-8111-111111111111';
    await AsyncStorage.setItem('@rokn/guest-storage-id/v1', guestId);
    mockSnapshot = {ready: true, session: null, epoch: 1};
    const guest = await captureAccountSessionBoundary();
    expect(guest.scope).toBe(`guest-${legacyHash(guestId)}`);
    mockSnapshot = {
      ready: true,
      session: {user: {id: 6}, api_token: 'test-session'},
      epoch: 2,
    };
    expect(() => assertAccountSessionBoundary(guest)).toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    expect((await captureAccountSessionBoundary()).scope).not.toBe(guest.scope);
  });
});
