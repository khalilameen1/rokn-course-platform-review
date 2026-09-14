import AsyncStorage from '@react-native-async-storage/async-storage';
import {accountScopedStorageKey} from '../constants/helpers';
import {getCourseCheckout} from './api/courseCheckout';

const storageKey = (productId: string) =>
  accountScopedStorageKey(
    `@rokn/native-course-checkout/v1/${encodeURIComponent(productId)}`,
  );

export const validCourseCheckoutId = (value: unknown): value is string =>
  typeof value === 'string' &&
  /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(value);

export async function readNativeCourseCheckout(
  productId: string,
): Promise<string | undefined> {
  try {
    const key = await storageKey(productId);
    const id = await AsyncStorage.getItem(key);
    if ((await storageKey(productId)) !== key) return undefined;
    return validCourseCheckoutId(id) ? id : undefined;
  } catch {
    // A missing recovery hint must never strand an otherwise valid receipt.
    return undefined;
  }
}

export async function rememberNativeCourseCheckout(
  productId: string,
  checkoutId: string,
) {
  const key = await storageKey(productId);
  if (!validCourseCheckoutId(checkoutId))
    throw new Error('COURSE_CHECKOUT_BINDING_INVALID');
  const previous = await AsyncStorage.getItem(key);
  if (validCourseCheckoutId(previous) && previous !== checkoutId) {
    const old = await getCourseCheckout(previous);
    if (
      !['completed', 'cancelled', 'expired', 'reconfirm_required'].includes(
        old.status,
      )
    ) {
      throw new Error('COURSE_CHECKOUT_BINDING_PENDING');
    }
  }
  if ((await storageKey(productId)) !== key)
    throw new Error('STORE_PURCHASE_ACCOUNT_CHANGED');
  // This write must succeed before opening the native payment sheet. The
  // signed receipt can then reconnect to the same authorization after restart.
  await AsyncStorage.setItem(key, checkoutId);
  if ((await storageKey(productId)) !== key)
    throw new Error('STORE_PURCHASE_ACCOUNT_CHANGED');
}

export async function clearNativeCourseCheckout(
  productId: string,
  checkoutId: string,
) {
  const key = await storageKey(productId);
  const stored = await AsyncStorage.getItem(key);
  if ((await storageKey(productId)) !== key)
    throw new Error('STORE_PURCHASE_ACCOUNT_CHANGED');
  if (stored === checkoutId) await AsyncStorage.removeItem(key);
}
