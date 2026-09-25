import AsyncStorage from '@react-native-async-storage/async-storage';
import {accountScopedStorageKey} from '../constants/helpers';
import {createKeyedAsyncQueue} from '../utils/keyedAsyncQueue';
import {getCourseCheckout} from './api/courseCheckout';

const serializeBindingMutation = createKeyedAsyncQueue();

const storageKey = (productId: string) =>
  accountScopedStorageKey(
    `@rokn/native-course-checkout/v1/${encodeURIComponent(productId)}`,
  );

const assertBindingOwner = async (productId: string, key: string) => {
  if ((await storageKey(productId)) !== key)
    throw new Error('STORE_PURCHASE_ACCOUNT_CHANGED');
};

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
  return serializeBindingMutation(key, async () => {
    await assertBindingOwner(productId, key);
    const previous = await AsyncStorage.getItem(key);
    await assertBindingOwner(productId, key);
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
    await assertBindingOwner(productId, key);
    // This write must succeed before opening the native payment sheet. The
    // signed receipt can then reconnect to the same authorization after restart.
    await AsyncStorage.setItem(key, checkoutId);
    await assertBindingOwner(productId, key);
  });
}

export async function clearNativeCourseCheckout(
  productId: string,
  checkoutId: string,
) {
  const key = await storageKey(productId);
  return serializeBindingMutation(key, async () => {
    await assertBindingOwner(productId, key);
    const stored = await AsyncStorage.getItem(key);
    await assertBindingOwner(productId, key);
    if (stored === checkoutId) await AsyncStorage.removeItem(key);
  });
}
