import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {clearPushDeviceRegistration} from './pushDeviceRegistration';
import {clearNotificationNavigation} from './pushNotificationNavigation';

/**
 * Account teardown owns coordination only. Both owners synchronously invalidate
 * their pending work before either storage/native cleanup is awaited.
 * Registration failure must remain visible to the session owner; navigation
 * cleanup must still run even when native token retirement fails.
 */
export const clearAccountPushState = async (
  ownerBoundary?: AccountSessionBoundary,
) => {
  if (ownerBoundary) assertAccountSessionBoundary(ownerBoundary);
  const [registration, navigation] = await Promise.allSettled([
    clearPushDeviceRegistration(ownerBoundary),
    clearNotificationNavigation(ownerBoundary),
  ]);
  if (registration.status === 'rejected') throw registration.reason;
  if (navigation.status === 'rejected') throw navigation.reason;
  if (ownerBoundary) assertAccountSessionBoundary(ownerBoundary);
  return registration.value;
};
