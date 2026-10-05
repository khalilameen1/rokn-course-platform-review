type Boundary = {epoch: number; scope: string};
let mockBoundary: Boundary = {epoch: 1, scope: 'account-a'};
let mockSession = {api_token: 'session-a'};
const mockValues = new Map<string, unknown>();
const mockRead = jest.fn();
const mockWrite = jest.fn();
const mockRemove = jest.fn();
const mockStorageKey = jest.fn();
const mockCapture = jest.fn();
const mockPost = jest.fn();
const mockDelete = jest.fn();
const mockPermissions = jest.fn();
const mockRequestPermission = jest.fn();
const mockAcquireToken = jest.fn();
const mockDeleteNativeToken = jest.fn();

jest.mock('react-native', () => ({
  Platform: {OS: 'android'},
  NativeModules: {RoknPushTokens: {deleteToken: () => mockDeleteNativeToken()}},
}));
jest.mock('expo-notifications', () => ({
  AndroidImportance: {DEFAULT: 3},
  getPermissionsAsync: () => mockPermissions(),
  requestPermissionsAsync: () => mockRequestPermission(),
  getDevicePushTokenAsync: () => mockAcquireToken(),
  setNotificationChannelAsync: jest.fn(async () => null),
  getAllScheduledNotificationsAsync: jest.fn(async () => []),
  cancelScheduledNotificationAsync: jest.fn(async () => undefined),
  addPushTokenListener: jest.fn(() => ({remove: jest.fn()})),
}));
jest.mock('@react-native-firebase/messaging', () => ({
  getMessaging: jest.fn(),
  registerDeviceForRemoteMessages: jest.fn(),
  getToken: jest.fn(),
  deleteToken: jest.fn(),
  onTokenRefresh: jest.fn(),
}));
jest.mock('../src/constants/helpers', () => ({
  AsyncKeys: {USER_DATA: 'USER_DATA'},
  captureAccountSessionBoundary: () => mockCapture(),
  assertAccountSessionBoundary: (boundary: Boundary) => {
    if (boundary.epoch !== mockBoundary.epoch || boundary.scope !== mockBoundary.scope)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  accountScopedStorageKey: (key: string, boundary?: Boundary) =>
    mockStorageKey(key, boundary),
  extractApiToken: (session: typeof mockSession | null) => session?.api_token || null,
  getCurrentAccountStorageScope: async () => mockBoundary.scope,
  getItem: (key: string) => mockRead(key),
  saveItem: (key: string, value: unknown) => mockWrite(key, value),
  removeItem: (key: string) => mockRemove(key),
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    post: (...args: unknown[]) => mockPost(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
}));
jest.mock('../src/services/installationIdentity', () => ({
  getInstallationId: async () => '11111111-1111-4111-8111-111111111111',
}));

// Real registration queue, token state, reminder preference reads and SDK
// adapters; only native, session/storage and HTTP boundaries are substituted.
import {
  reconcilePushRegistration,
  registerPushDeviceIfEligible,
  unregisterPushDevice,
} from '../src/services/pushDeviceRegistration';
import {
  invalidateLocalPushDeviceRegistration,
  PUSH_TOKEN_KEY,
} from '../src/services/pushDeviceState';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(accept => {resolve = accept;});
  return {promise, resolve};
};
const drain = async () => {
  for (let index = 0; index < 100; index += 1) await Promise.resolve();
};
const replaceAccount = (sameAccount = false) => {
  mockBoundary = {epoch: 2, scope: sameAccount ? 'account-a' : 'account-b'};
  mockSession = {api_token: sameAccount ? 'session-a' : 'session-b'};
};
const settled = (flight: Promise<unknown>) => flight.catch(error => error);

describe('push mutations keep the account captured before queue admission', () => {
  beforeEach(() => {
    mockBoundary = {epoch: 1, scope: 'account-a'};
    mockSession = {api_token: 'session-a'};
    mockValues.clear();
    for (const account of ['account-a', 'account-b']) {
      mockValues.set(`PREF_NOTIFICATIONS:${account}`, true);
      mockValues.set(`${PUSH_TOKEN_KEY}:${account}`, `stored-${account}`);
    }
    mockCapture.mockReset().mockImplementation(async () => ({...mockBoundary}));
    mockStorageKey.mockReset().mockImplementation(async (key: string, boundary?: Boundary) =>
      `${key}:${boundary?.scope ?? mockBoundary.scope}`,
    );
    mockRead.mockReset().mockImplementation(async (key: string) =>
      key === 'USER_DATA' ? mockSession : mockValues.get(key) ?? null,
    );
    mockWrite.mockReset().mockImplementation(async (key: string, value: unknown) => {
      mockValues.set(key, value);
      return true;
    });
    mockRemove.mockReset().mockImplementation(async (key: string) => {
      mockValues.delete(key);
      return true;
    });
    mockPost.mockReset().mockResolvedValue({data: {}});
    mockDelete.mockReset().mockResolvedValue({data: {}});
    mockPermissions.mockReset().mockResolvedValue({granted: true, status: 'granted', canAskAgain: true});
    mockRequestPermission.mockReset().mockResolvedValue({granted: true, status: 'granted'});
    mockAcquireToken.mockReset().mockResolvedValue({data: 'fresh-fcm-token'});
    mockDeleteNativeToken.mockReset().mockResolvedValue(true);
  });

  it.each([false, true])('retires queued registration before any replacement-session IO (same account %s)', async sameAccount => {
    const deleting = deferred<boolean>();
    mockDeleteNativeToken.mockReturnValueOnce(deleting.promise);
    const optOut = settled(unregisterPushDevice());
    await drain();
    expect(mockDeleteNativeToken).toHaveBeenCalledTimes(1);
    const queuedRegistration = settled(registerPushDeviceIfEligible());
    await drain();
    expect(mockCapture).toHaveBeenCalledTimes(2);
    replaceAccount(sameAccount);
    deleting.resolve(true);
    await optOut;
    expect(await queuedRegistration).toEqual(new Error('ACCOUNT_CHANGED_DURING_REQUEST'));
    expect(mockAcquireToken).not.toHaveBeenCalled();
    expect(mockPost).not.toHaveBeenCalled();
    expect(mockValues.get(`${PUSH_TOKEN_KEY}:account-b`)).toBe('stored-account-b');
    await expect(registerPushDeviceIfEligible()).resolves.toBe(true);
    expect(mockPost).toHaveBeenCalledTimes(1);
  });

  it('does not rebind a queued opt-out to the next account', async () => {
    const acquiring = deferred<{data: string}>();
    mockAcquireToken.mockReturnValueOnce(acquiring.promise);
    const registering = settled(registerPushDeviceIfEligible());
    await drain();
    expect(mockAcquireToken).toHaveBeenCalledTimes(1);
    const queuedOptOut = settled(unregisterPushDevice());
    await drain();
    replaceAccount();
    acquiring.resolve({data: 'old-fcm-token'});
    await registering;
    expect(await queuedOptOut).toEqual(new Error('ACCOUNT_CHANGED_DURING_REQUEST'));
    expect(mockPost).not.toHaveBeenCalled();
    expect(mockDelete).not.toHaveBeenCalled();
    expect(mockDeleteNativeToken).not.toHaveBeenCalled();
    expect(mockValues.get(`${PUSH_TOKEN_KEY}:account-b`)).toBe('stored-account-b');
  });

  it('rejects a retired explicit opt-out without invalidating the new registration generation', async () => {
    const oldOwner = {...mockBoundary};
    replaceAccount();
    const acquiring = deferred<{data: string}>();
    mockAcquireToken.mockReturnValueOnce(acquiring.promise);
    const registering = registerPushDeviceIfEligible({ownerBoundary: mockBoundary});
    await drain();
    expect(mockAcquireToken).toHaveBeenCalledTimes(1);
    await expect(unregisterPushDevice(oldOwner)).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
    acquiring.resolve({data: 'new-account-fcm-token'});
    await expect(registering).resolves.toBe(true);
    expect(mockDeleteNativeToken).not.toHaveBeenCalled();
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(mockValues.get(`${PUSH_TOKEN_KEY}:account-b`)).toBe('new-account-fcm-token');
  });

  it('does not open the OS prompt after its permission read crosses an account replacement', async () => {
    const permissions = deferred<{granted: boolean; canAskAgain: boolean}>();
    mockPermissions.mockReturnValueOnce(permissions.promise);
    const registering = settled(registerPushDeviceIfEligible({requestPermission: true}));
    await drain();
    expect(mockPermissions).toHaveBeenCalledTimes(1);
    replaceAccount();
    permissions.resolve({granted: false, canAskAgain: true});
    expect(await registering).toEqual(new Error('ACCOUNT_CHANGED_DURING_REQUEST'));
    expect(mockRequestPermission).not.toHaveBeenCalled();
    expect(mockAcquireToken).not.toHaveBeenCalled();
    expect(mockPost).not.toHaveBeenCalled();
  });

  it('does not reconcile an old preference into the current account', async () => {
    const enabled = deferred<boolean>();
    mockRead.mockImplementation(async (key: string) => {
      if (key === 'PREF_NOTIFICATIONS:account-a') return enabled.promise;
      return key === 'USER_DATA' ? mockSession : mockValues.get(key) ?? null;
    });
    const reconcile = settled(reconcilePushRegistration());
    await drain();
    replaceAccount();
    enabled.resolve(false);
    expect(await reconcile).toEqual(new Error('ACCOUNT_CHANGED_DURING_REQUEST'));
    expect(mockDelete).not.toHaveBeenCalled();
    expect(mockDeleteNativeToken).not.toHaveBeenCalled();
    expect(mockAcquireToken).not.toHaveBeenCalled();
  });

  it('rechecks the supplied owner after resolving its storage key before native invalidation', async () => {
    const key = deferred<string>();
    const owner = {...mockBoundary};
    mockStorageKey.mockReturnValueOnce(key.promise);
    const invalidating = settled(invalidateLocalPushDeviceRegistration(owner));
    replaceAccount();
    key.resolve(`${PUSH_TOKEN_KEY}:account-a`);
    expect(await invalidating).toEqual(new Error('ACCOUNT_CHANGED_DURING_REQUEST'));
    expect(mockDeleteNativeToken).not.toHaveBeenCalled();
    expect(mockRemove).not.toHaveBeenCalled();
  });

  it('compensates a landed registration only against its captured old bearer', async () => {
    const receipt = deferred<{data: object}>();
    mockPost.mockReturnValueOnce(receipt.promise);
    const registering = settled(registerPushDeviceIfEligible());
    await drain();
    expect(mockPost).toHaveBeenCalledTimes(1);
    replaceAccount();
    receipt.resolve({data: {}});
    expect(await registering).toEqual(new Error('ACCOUNT_CHANGED_DURING_REQUEST'));
    expect(mockDelete).toHaveBeenCalledWith('user/device-token', {
      data: {device_token: 'fresh-fcm-token'},
      headers: {Authorization: 'Bearer session-a'},
      skipPersistedSessionInvalidation: true,
    });
    expect(mockWrite).not.toHaveBeenCalled();
    expect(mockDeleteNativeToken).not.toHaveBeenCalled();
  });

  it('retains offline opt-out invalidation for the same account', async () => {
    mockDelete.mockRejectedValueOnce(new Error('offline'));
    await expect(unregisterPushDevice(mockBoundary)).resolves.toBe(false);
    expect(mockDeleteNativeToken).toHaveBeenCalledTimes(1);
    expect(mockRemove).toHaveBeenCalledWith(`${PUSH_TOKEN_KEY}:account-a`);
    expect(mockValues.get(`${PUSH_TOKEN_KEY}:account-b`)).toBe('stored-account-b');
  });
});
