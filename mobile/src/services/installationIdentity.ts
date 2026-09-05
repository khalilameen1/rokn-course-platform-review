import AsyncStorage from '@react-native-async-storage/async-storage';
import {secureRandomUuid} from '../utils/secureRandom';
import {settleWithin} from '../utils/settleWithin';

const INSTALLATION_ID_KEY = '@rokn/installation-id/v1';
const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const INSTALLATION_ID_READ_BUDGET_MS = 400;
const REQUIRED_INSTALLATION_ID_READ_BUDGET_MS = 3_000;

let installationIdPromise: Promise<string | null> | null = null;

const loadInstallationId = () => {
  if (!installationIdPromise) {
    const flight = (async () => {
      try {
        const stored = String(
          (await AsyncStorage.getItem(INSTALLATION_ID_KEY)) || '',
        ).toLowerCase();
        if (UUID_PATTERN.test(stored)) return stored;

        const created = secureRandomUuid();
        await AsyncStorage.setItem(INSTALLATION_ID_KEY, created);
        return created;
      } catch {
        return null;
      }
    })();
    installationIdPromise = flight;
    void flight.then(value => {
      if (!value && installationIdPromise === flight) {
        installationIdPromise = null;
      }
    });
  }
  return installationIdPromise;
};

/**
 * Installation identity improves rollout bucketing and device diagnostics, but
 * it is not part of the public API contract. A damaged or busy native storage
 * bridge must therefore never hold catalogue/auth discovery before dispatch.
 * The shared load keeps running so a later request can still attach the ID.
 */
export const getInstallationId = () =>
  settleWithin(loadInstallationId(), null, INSTALLATION_ID_READ_BUDGET_MS);

/**
 * Authentication cannot omit this identity: the server's normal
 * single-device policy rejects an empty device_id. Keep ordinary public reads
 * fast, but fail before opening an external provider when durable local
 * storage cannot supply the identity required to finish the login.
 */
export const getRequiredInstallationId = async () => {
  const installationId = await settleWithin(
    loadInstallationId(),
    null,
    REQUIRED_INSTALLATION_ID_READ_BUDGET_MS,
  );
  if (!installationId) {
    throw new Error('SESSION_STORAGE_UNAVAILABLE_INSTALLATION_ID');
  }
  return installationId;
};
