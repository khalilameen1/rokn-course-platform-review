import * as SecureStore from 'expo-secure-store';
import {NativeModules, Platform} from 'react-native';
import {sha256Hex} from '../utils/sha256';

const SECURE_TOKEN_KEY = 'rokn.auth.api-token.v2';
export const SECURE_SESSION_BINDING_KEY = 'rokn.auth.session-binding.v1';
const SECURE_STORAGE_PROBE_KEY = 'rokn.auth.storage-probe.v1';
export const PENDING_SOCIAL_AUTH_KEY = 'rokn.auth.pending-social.v1';

export const secureStoreOptionsForPlatform = (
  platform: string,
): SecureStore.SecureStoreOptions =>
  platform === 'ios'
    ? {keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY}
    : {};

const SECURE_OPTIONS = secureStoreOptionsForPlatform(Platform.OS);

type AndroidSecureSessionModule = {
  setItem: (key: string, value: string) => Promise<void>;
  getItem: (key: string) => Promise<string | null>;
  deleteItem: (key: string) => Promise<void>;
};

const androidSecureSession = () =>
  NativeModules.RoknSecureSession as AndroidSecureSessionModule | undefined;

const storageFailure = (stage: string, error?: unknown) => {
  const nativeCode =
    typeof error === 'object' &&
    error !== null &&
    'code' in error &&
    typeof error.code === 'string'
      ? error.code
          .toUpperCase()
          .replace(/[^A-Z0-9]+/g, '_')
          .replace(/^_+|_+$/g, '')
          .slice(0, 28)
      : '';
  return new Error(
    `SESSION_STORAGE_UNAVAILABLE_${stage}${nativeCode ? `_${nativeCode}` : ''}`,
  );
};

export const secureSetItem = (key: string, value: string) => {
  if (Platform.OS !== 'android') {
    return SecureStore.setItemAsync(key, value, SECURE_OPTIONS);
  }
  const module = androidSecureSession();
  if (!module?.setItem) throw storageFailure('MODULE');
  return module.setItem(key, value);
};

export const secureGetItem = (key: string) => {
  if (Platform.OS !== 'android') {
    return SecureStore.getItemAsync(key, SECURE_OPTIONS);
  }
  const module = androidSecureSession();
  if (!module?.getItem) throw storageFailure('MODULE');
  return module.getItem(key);
};

export const secureDeleteItem = (key: string) => {
  if (Platform.OS !== 'android') {
    return SecureStore.deleteItemAsync(key, SECURE_OPTIONS);
  }
  const module = androidSecureSession();
  if (!module?.deleteItem) throw storageFailure('MODULE');
  return module.deleteItem(key);
};

export const readSecureToken = async (): Promise<string | null> => {
  const current = String((await secureGetItem(SECURE_TOKEN_KEY)) || '').trim();
  return current || null;
};

export const writeSecureToken = async (token: string) => {
  const normalized = token.trim();
  if (!normalized) throw storageFailure('MISSING_TOKEN');
  await secureSetItem(SECURE_TOKEN_KEY, normalized);
};

export const deleteSecureTokens = () =>
  Promise.all([
    secureDeleteItem(SECURE_TOKEN_KEY),
    secureDeleteItem(SECURE_SESSION_BINDING_KEY),
  ]);

export const sessionBinding = async (owner: string, token: string) =>
  JSON.stringify({owner, tokenHash: await sha256Hex(token)});

export const bindingMatches = async (
  rawBinding: string | null,
  owner: string,
  token: string,
) => {
  if (!rawBinding) return null;
  try {
    const binding = JSON.parse(rawBinding) as {
      owner?: unknown;
      tokenHash?: unknown;
    };
    return (
      binding.owner === owner && binding.tokenHash === (await sha256Hex(token))
    );
  } catch {
    return false;
  }
};

let storageAvailabilityPromise: Promise<void> | null = null;

const secureStorageAvailable = async () => {
  if (Platform.OS !== 'android') return SecureStore.isAvailableAsync();
  return Boolean(androidSecureSession());
};

const performStorageAvailabilityCheck = async () => {
  if (!(await secureStorageAvailable())) throw storageFailure('MODULE');
  const probe = `rokn-${Date.now()}`;
  try {
    await secureSetItem(SECURE_STORAGE_PROBE_KEY, probe);
  } catch (error) {
    throw storageFailure('WRITE', error);
  }

  try {
    const restored = await secureGetItem(SECURE_STORAGE_PROBE_KEY);
    if (restored !== probe) throw storageFailure('ROUNDTRIP');
  } catch (error) {
    if (
      error instanceof Error &&
      error.message.startsWith('SESSION_STORAGE_UNAVAILABLE_')
    ) {
      throw error;
    }
    throw storageFailure('READ', error);
  }

  try {
    await secureDeleteItem(SECURE_STORAGE_PROBE_KEY);
  } catch (error) {
    throw storageFailure('DELETE', error);
  }
};

export const assertSecureSessionStorageAvailable = async () => {
  if (!storageAvailabilityPromise) {
    storageAvailabilityPromise = performStorageAvailabilityCheck().catch(
      error => {
        storageAvailabilityPromise = null;
        throw error;
      },
    );
  }
  await storageAvailabilityPromise;
};

export const resetSecureSessionStorageForTests = () => {
  storageAvailabilityPromise = null;
};
