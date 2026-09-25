import {finishTransaction, type Purchase} from 'expo-iap';
import {publicRequest} from '../constants/api';
import {accountScopedStorageKey} from '../constants/helpers';
import {
  IS_APP_STORE_DISTRIBUTION,
  IS_PLAY_DISTRIBUTION,
} from '../constants/distribution';
import {firstBoolean, isApiRecord, payload} from './api/common';
import {
  clearNativeCourseCheckout,
  readNativeCourseCheckout,
  validCourseCheckoutId,
} from './nativeCourseCheckoutBinding';
import type {CoinCheckoutResult} from './coinCheckoutTypes';

type StoreVerificationResult = {
  checkout?: unknown;
  coins_added?: unknown;
  credited?: unknown;
  financial_status?: unknown;
  finalize_transaction?: unknown;
  store_finalized?: unknown;
  already_processed?: unknown;
};

const processing = new Map<
  string,
  {accountScope: string; promise: Promise<CoinCheckoutResult>}
>();
const provider = () => {
  if (IS_PLAY_DISTRIBUTION) return 'google' as const;
  if (IS_APP_STORE_DISTRIBUTION) return 'apple' as const;
  throw new Error('NATIVE_STORE_UNAVAILABLE_FOR_DISTRIBUTION');
};

const purchaseTransactionId = (purchase: Purchase) =>
  'transactionId' in purchase && purchase.transactionId
    ? String(purchase.transactionId)
    : undefined;

export const nativePurchaseKey = (purchase: Purchase) =>
  String(
    purchase.purchaseToken ||
      purchaseTransactionId(purchase) ||
      `${purchase.store}:${purchase.productId}:${purchase.id}`,
  );

export const verifyAndFinishNativePurchase = async (
  purchase: Purchase,
  accountScope: string,
): Promise<CoinCheckoutResult> => {
  if (purchase.purchaseState === 'pending') {
    return {
      success: false,
      pending: true,
      cancelled: false,
      coinsAdded: 0,
      orderRef: purchaseTransactionId(purchase),
    };
  }
  if (purchase.purchaseState !== 'purchased') {
    throw new Error('STORE_PURCHASE_NOT_COMPLETED');
  }
  const purchaseToken = String(purchase.purchaseToken || '').trim();
  if (!purchaseToken) throw new Error('STORE_PURCHASE_TOKEN_MISSING');

  const key = nativePurchaseKey(purchase);
  const existing = processing.get(key);
  if (existing) {
    if (existing.accountScope !== accountScope) {
      throw new Error('STORE_PURCHASE_ACCOUNT_CHANGED');
    }
    return existing.promise;
  }

  const operation: Promise<CoinCheckoutResult> = (async () => {
    const currentScope = await accountScopedStorageKey(
      '@rokn/native-store-reconciliation/v1',
    );
    if (currentScope !== accountScope) {
      throw new Error('STORE_PURCHASE_ACCOUNT_CHANGED');
    }
    const receiptCheckoutId =
      'obfuscatedProfileIdAndroid' in purchase
        ? String(purchase.obfuscatedProfileIdAndroid || '').trim()
        : '';
    const courseCheckoutId = validCourseCheckoutId(receiptCheckoutId)
      ? receiptCheckoutId
      : await readNativeCourseCheckout(purchase.productId);
    const response = await publicRequest.post('store-purchases/verify', {
      provider: provider(),
      product_id: purchase.productId,
      purchase_token: purchaseToken,
      transaction_id: purchaseTransactionId(purchase),
      ...(courseCheckoutId ? {checkout_id: courseCheckoutId} : {}),
    });
    const verified = payload<StoreVerificationResult>(response);
    if (firstBoolean(verified.finalize_transaction) !== true) {
      throw new Error('STORE_SERVER_DID_NOT_AUTHORIZE_FINALIZATION');
    }
    const coinsAdded = Number(verified.coins_added);
    const credited =
      firstBoolean(verified.credited) ??
      (Number.isSafeInteger(coinsAdded) && coinsAdded > 0);
    if (
      !Number.isSafeInteger(coinsAdded) ||
      coinsAdded < 0 ||
      (credited && coinsAdded <= 0)
    ) {
      throw new Error('STORE_VERIFICATION_CONTRACT_INVALID');
    }

    // Consumables are finalized only after the backend has atomically recorded
    // and credited them. A network/server failure leaves the transaction in the
    // store queue, so it is recovered without asking the learner to pay again.
    // Google may already have consumed the receipt on the backend. Asking the
    // bridge to consume it again can report ITEM_NOT_OWNED after a valid credit.
    // Apple still requires the device to finish its StoreKit transaction.
    if (
      !(IS_PLAY_DISTRIBUTION && firstBoolean(verified.store_finalized) === true)
    ) {
      await finishTransaction({purchase, isConsumable: true});
    }
    if (
      courseCheckoutId &&
      isApiRecord(verified.checkout) &&
      verified.checkout.id === courseCheckoutId &&
      ['completed', 'cancelled', 'expired', 'reconfirm_required'].includes(
        String(verified.checkout.status),
      )
    ) {
      await clearNativeCourseCheckout(purchase.productId, courseCheckoutId);
    }

    return {
      success: credited,
      pending: false,
      cancelled: !credited,
      coinsAdded: credited ? coinsAdded : 0,
      orderRef: purchaseTransactionId(purchase),
    };
  })();
  processing.set(key, {accountScope, promise: operation});
  try {
    return await operation;
  } finally {
    if (processing.get(key)?.promise === operation) processing.delete(key);
  }
};
