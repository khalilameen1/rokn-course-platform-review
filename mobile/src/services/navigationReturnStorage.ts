import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  type AccountSessionBoundary,
} from '../constants/helpers';

/**
 * Device storage for in-app return destinations: the device-wide login
 * return envelope and the account-scoped checkout return receipt. The
 * navigation modules own validation, ordering and expiry.
 */
const PENDING_LOGIN_RETURN_KEY = '@rokn/pending-login-return/v1';
const CHECKOUT_RETURN_KEY = '@rokn/pending-checkout-return/v1';

export const loginReturnStorageKey = PENDING_LOGIN_RETURN_KEY;

export const readLoginReturn = () =>
  AsyncStorage.getItem(PENDING_LOGIN_RETURN_KEY);

export const writeLoginReturn = (receipt: string) =>
  AsyncStorage.setItem(PENDING_LOGIN_RETURN_KEY, receipt);

export const removeLoginReturn = () =>
  AsyncStorage.removeItem(PENDING_LOGIN_RETURN_KEY);

export const checkoutReturnStorageKey = (boundary: AccountSessionBoundary) =>
  accountScopedStorageKey(CHECKOUT_RETURN_KEY, boundary);

export const readCheckoutReturn = (storageKey: string) =>
  AsyncStorage.getItem(storageKey);

export const writeCheckoutReturn = (storageKey: string, receipt: string) =>
  AsyncStorage.setItem(storageKey, receipt);

export const removeCheckoutReturn = (storageKey: string) =>
  AsyncStorage.removeItem(storageKey);
