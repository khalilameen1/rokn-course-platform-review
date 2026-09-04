import {
  fetchProducts,
  finishTransaction,
  getAvailablePurchases,
  initConnection,
  purchaseErrorListener,
  purchaseUpdatedListener,
  requestPurchase,
  type Product,
  type Purchase,
} from 'expo-iap';
import {publicRequest} from '../constants/api';
import {accountScopedStorageKey} from '../constants/helpers';
import {
  DISTRIBUTION_CHANNEL,
  IS_APP_STORE_DISTRIBUTION,
  IS_PLAY_DISTRIBUTION,
} from '../constants/distribution';
import type {CoinPackage} from './api/coinPackageMapper';
import {firstBoolean, payload} from './api/common';
import {reportClientError} from './operationalTelemetry';
import {errorCode} from '../utils/errorPayload';

type StoreBillingContext = {
  google_obfuscated_account_id?: unknown;
  apple_app_account_token?: unknown;
};

type StoreVerificationResult = {
  coins_added?: unknown;
  credited?: unknown;
  financial_status?: unknown;
  finalize_transaction?: unknown;
  already_processed?: unknown;
};

export type NativeCoinCheckoutResult = {
  success: boolean;
  pending: boolean;
  cancelled: boolean;
  coinsAdded: number;
  orderRef?: string;
};

type ActivePurchase = {
  productId: string;
  accountScope: string;
  accountBinding: string;
  resolve: (value: NativeCoinCheckoutResult) => void;
  reject: (reason?: unknown) => void;
  timer: ReturnType<typeof setTimeout>;
};

type StoreBillingOwner = {
  accountScope: string;
  accountBinding: string;
};

let connectionPromise: Promise<void> | null = null;
let listenersReady = false;
const activePurchases = new Map<string, ActivePurchase>();
const processing = new Map<
  string,
  {accountScope: string; promise: Promise<NativeCoinCheckoutResult>}
>();
const reconciliationFlights = new Map<
  string,
  Promise<{pending: boolean; pendingProductIds: string[]; reconciled: number}>
>();
const emittedCredits = new Set<string>();
const MAX_EMITTED_CREDIT_KEYS = 128;
const creditListeners = new Set<(result: NativeCoinCheckoutResult) => void>();

const emitCredit = (result: NativeCoinCheckoutResult) => {
  if (result.success) creditListeners.forEach(listener => listener(result));
};

const emitPurchaseCredit = (
  purchase: Purchase,
  result: NativeCoinCheckoutResult,
  accountScope: string,
) => {
  const key = purchaseKey(purchase);
  if (!result.success || emittedCredits.has(key)) return;
  void accountScopedStorageKey('@rokn/native-store-reconciliation/v1').then(
    currentScope => {
      if (currentScope !== accountScope || emittedCredits.has(key)) return;
      emittedCredits.add(key);
      while (emittedCredits.size > MAX_EMITTED_CREDIT_KEYS) {
        const oldest = emittedCredits.values().next().value;
        if (typeof oldest !== 'string') break;
        emittedCredits.delete(oldest);
      }
      emitCredit(result);
    },
  );
};

const provider = () => {
  if (IS_PLAY_DISTRIBUTION) return 'google' as const;
  if (IS_APP_STORE_DISTRIBUTION) return 'apple' as const;
  throw new Error('NATIVE_STORE_UNAVAILABLE_FOR_DISTRIBUTION');
};

const normalizedBinding = (value: unknown) =>
  String(value || '')
    .trim()
    .toLowerCase();

const contextBinding = (context: StoreBillingContext) =>
  normalizedBinding(
    IS_PLAY_DISTRIBUTION
      ? context.google_obfuscated_account_id
      : context.apple_app_account_token,
  );

const purchaseBinding = (purchase: Purchase) =>
  normalizedBinding(
    IS_PLAY_DISTRIBUTION && 'obfuscatedAccountIdAndroid' in purchase
      ? purchase.obfuscatedAccountIdAndroid
      : IS_APP_STORE_DISTRIBUTION && 'appAccountToken' in purchase
      ? purchase.appAccountToken
      : '',
  );

const currentStoreBillingOwner =
  async (): Promise<StoreBillingOwner | null> => {
    const accountScope = await accountScopedStorageKey(
      '@rokn/native-store-reconciliation/v1',
    );
    try {
      const context = payload<StoreBillingContext>(
        await publicRequest.get('store-billing/context'),
      );
      const accountBinding = contextBinding(context);
      const confirmedAccountScope = await accountScopedStorageKey(
        '@rokn/native-store-reconciliation/v1',
      );
      return accountBinding && confirmedAccountScope === accountScope
        ? {accountScope, accountBinding}
        : null;
    } catch {
      // Guests and interrupted sessions do not own an authenticated store
      // receipt. Leave it in the provider queue for its bound Rokn account.
      return null;
    }
  };

const purchaseBelongsTo = (purchase: Purchase, owner: StoreBillingOwner) => {
  const receiptBinding = purchaseBinding(purchase);
  if (receiptBinding) return receiptBinding === owner.accountBinding;

  // Some bridges omit the account binding after a process restart even though
  // it remains signed inside the provider receipt. Let the backend inspect an
  // unbound callback: provider verification is authoritative and enforces the
  // Rokn account binding before a single coin is credited.
  return true;
};

const receiptBelongsToAnotherAccount = (error: unknown) =>
  ['store_account_mismatch', 'store_purchase_already_claimed'].includes(
    errorCode(error).trim().toLowerCase(),
  );

const packageProductId = (coinPackage: CoinPackage) =>
  IS_PLAY_DISTRIBUTION
    ? coinPackage.storeProductIds?.google
    : IS_APP_STORE_DISTRIBUTION
    ? coinPackage.storeProductIds?.apple
    : undefined;

const purchaseTransactionId = (purchase: Purchase) =>
  'transactionId' in purchase && purchase.transactionId
    ? String(purchase.transactionId)
    : undefined;

const purchaseKey = (purchase: Purchase) =>
  String(
    purchase.purchaseToken ||
      purchaseTransactionId(purchase) ||
      `${purchase.store}:${purchase.productId}:${purchase.id}`,
  );

const cancelledError = (error: {code?: unknown}) => {
  const code = String(error.code || '').toLowerCase();
  return code.includes('cancel') || code.includes('user');
};

const settleActive = (
  productId: string,
  accountScope: string,
  action: (active: ActivePurchase) => void,
) => {
  const current = activePurchases.get(accountScope);
  if (!current || current.productId !== productId) return;
  activePurchases.delete(accountScope);
  clearTimeout(current.timer);
  action(current);
};

const verifyAndFinish = async (
  purchase: Purchase,
  accountScope: string,
): Promise<NativeCoinCheckoutResult> => {
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

  const key = purchaseKey(purchase);
  const existing = processing.get(key);
  if (existing) {
    if (existing.accountScope !== accountScope) {
      throw new Error('STORE_PURCHASE_ACCOUNT_CHANGED');
    }
    return existing.promise;
  }

  const operation: Promise<NativeCoinCheckoutResult> = (async () => {
    const currentScope = await accountScopedStorageKey(
      '@rokn/native-store-reconciliation/v1',
    );
    if (currentScope !== accountScope) {
      throw new Error('STORE_PURCHASE_ACCOUNT_CHANGED');
    }
    const response = await publicRequest.post('store-purchases/verify', {
      provider: provider(),
      product_id: purchase.productId,
      purchase_token: purchaseToken,
      transaction_id: purchaseTransactionId(purchase),
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
    await finishTransaction({purchase, isConsumable: true});

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

const handlePurchaseUpdate = async (purchase: Purchase) => {
  const owner = await currentStoreBillingOwner();
  if (!owner || !purchaseBelongsTo(purchase, owner)) return;
  try {
    const result = await verifyAndFinish(purchase, owner.accountScope);
    emitPurchaseCredit(purchase, result, owner.accountScope);
    settleActive(purchase.productId, owner.accountScope, active =>
      active.resolve(result),
    );
  } catch (error: unknown) {
    if (receiptBelongsToAnotherAccount(error)) {
      // An unbound callback from a previous Rokn account can be published by
      // the store while this account has its own sheet open. The backend has
      // rejected its owner authoritatively; it must not reject the new
      // account's unrelated active purchase.
      return;
    }
    reportClientError(
      error instanceof Error
        ? error
        : new Error('native_store_verification_failed'),
      {source: 'native_store_billing'},
    );
    settleActive(purchase.productId, owner.accountScope, active =>
      active.reject(error),
    );
  }
};

const installListeners = () => {
  if (listenersReady) return;
  listenersReady = true;
  purchaseUpdatedListener(purchase => {
    void handlePurchaseUpdate(purchase);
  });
  purchaseErrorListener(error => {
    const productId =
      'productId' in error && error.productId
        ? String(error.productId)
        : undefined;
    const candidates = [...activePurchases.values()].filter(
      active => !productId || active.productId === productId,
    );

    // Store error callbacks do not carry our account binding. When two Rokn
    // accounts have an in-flight request for the same product, attributing an
    // unbound error would let an old sheet close the new account's checkout.
    // The scoped requestPurchase rejection or timeout will settle each request.
    if (candidates.length !== 1) return;
    const [current] = candidates;
    activePurchases.delete(current.accountScope);
    clearTimeout(current.timer);
    if (cancelledError(error)) {
      current.resolve({
        success: false,
        pending: false,
        cancelled: true,
        coinsAdded: 0,
      });
      return;
    }
    current.reject(
      new Error(String(error.code || error.message || 'STORE_PURCHASE_FAILED')),
    );
  });
};

const ensureConnection = async () => {
  if (!IS_PLAY_DISTRIBUTION && !IS_APP_STORE_DISTRIBUTION) {
    throw new Error('NATIVE_STORE_UNAVAILABLE_FOR_DISTRIBUTION');
  }
  if (!connectionPromise) {
    connectionPromise = (async () => {
      const connected = await initConnection();
      if (!connected) throw new Error('STORE_CONNECTION_UNAVAILABLE');
      installListeners();
    })().catch(error => {
      connectionPromise = null;
      throw error;
    });
  }
  await connectionPromise;
};

const reconcileOutstandingPurchases = async (
  suppliedOwner?: StoreBillingOwner | null,
) => {
  const owner = suppliedOwner ?? (await currentStoreBillingOwner());
  if (!owner) {
    return {pending: false, pendingProductIds: [], reconciled: 0};
  }
  const existing = reconciliationFlights.get(owner.accountScope);
  if (existing) return existing;

  const operation = (async () => {
    const purchases = await getAvailablePurchases({
      alsoPublishToEventListenerIOS: false,
      onlyIncludeActiveItemsIOS: false,
    });
    const pendingProductIds = new Set<string>();
    let reconciled = 0;
    for (const purchase of purchases) {
      if (!purchaseBelongsTo(purchase, owner)) continue;
      if (purchase.purchaseState === 'pending') {
        pendingProductIds.add(purchase.productId);
        continue;
      }
      if (purchase.purchaseState !== 'purchased') continue;

      try {
        const result = await verifyAndFinish(purchase, owner.accountScope);
        emitPurchaseCredit(purchase, result, owner.accountScope);
        settleActive(purchase.productId, owner.accountScope, active =>
          active.resolve(result),
        );
        reconciled += result.success ? 1 : 0;
      } catch (error: unknown) {
        // One unresolved receipt must not hide later receipts in the queue.
        // A receipt authoritatively bound to another Rokn account must not
        // block this learner from buying the same SKU. Other failures remain
        // pending because ignoring those could lose a genuinely paid receipt.
        if (!receiptBelongsToAnotherAccount(error)) {
          pendingProductIds.add(purchase.productId);
        }
        reportClientError(
          error instanceof Error
            ? error
            : new Error('native_store_reconciliation_failed'),
          {source: 'native_store_reconciliation'},
        );
        settleActive(purchase.productId, owner.accountScope, active =>
          active.reject(error),
        );
      }
    }
    return {
      pending: pendingProductIds.size > 0,
      pendingProductIds: [...pendingProductIds],
      reconciled,
    };
  })();
  reconciliationFlights.set(owner.accountScope, operation);
  try {
    return await operation;
  } finally {
    if (reconciliationFlights.get(owner.accountScope) === operation) {
      reconciliationFlights.delete(owner.accountScope);
    }
  }
};

export const reconcileNativeStorePurchases = async () => {
  await ensureConnection();
  return reconcileOutstandingPurchases();
};

export const hydrateNativeStorePackages = async (
  packages: CoinPackage[],
): Promise<CoinPackage[]> => {
  await ensureConnection();
  await reconcileOutstandingPurchases();
  const configured = packages
    .map(coinPackage => ({
      coinPackage,
      productId: packageProductId(coinPackage),
    }))
    .filter((entry): entry is {coinPackage: CoinPackage; productId: string} =>
      Boolean(entry.productId),
    );
  if (!configured.length) return [];

  const products = (await fetchProducts({
    skus: configured.map(item => item.productId),
    type: 'in-app',
  })) as Product[];
  const byId = new Map(products.map(product => [product.id, product]));

  return configured.flatMap(({coinPackage, productId}) => {
    const product = byId.get(productId);
    if (!product) return [];
    return [
      {
        ...coinPackage,
        price: Number.isFinite(Number(product.price))
          ? Number(product.price)
          : coinPackage.price,
        displayPrice: product.displayPrice,
      },
    ];
  });
};

export const purchaseNativeCoinPackage = async (
  coinPackage: CoinPackage,
): Promise<NativeCoinCheckoutResult> => {
  const productId = packageProductId(coinPackage);
  if (!productId) throw new Error('STORE_PRODUCT_NOT_CONFIGURED');

  await ensureConnection();
  const owner = await currentStoreBillingOwner();
  if (!owner) throw new Error('STORE_ACCOUNT_BINDING_UNAVAILABLE');
  if (activePurchases.has(owner.accountScope)) {
    throw new Error('STORE_PURCHASE_ALREADY_IN_PROGRESS');
  }
  const outstanding = await reconcileOutstandingPurchases(owner);
  if (outstanding.pendingProductIds.includes(productId)) {
    throw new Error('STORE_PURCHASE_PENDING');
  }
  const confirmedAccountScope = await accountScopedStorageKey(
    '@rokn/native-store-reconciliation/v1',
  );
  if (confirmedAccountScope !== owner.accountScope) {
    throw new Error('STORE_PURCHASE_ACCOUNT_CHANGED');
  }

  let resolvePurchase!: (value: NativeCoinCheckoutResult) => void;
  let rejectPurchase!: (reason?: unknown) => void;
  const outcome = new Promise<NativeCoinCheckoutResult>((resolve, reject) => {
    resolvePurchase = resolve;
    rejectPurchase = reject;
  });
  const timer = setTimeout(() => {
    settleActive(productId, owner.accountScope, active =>
      active.resolve({
        success: false,
        pending: true,
        cancelled: false,
        coinsAdded: 0,
      }),
    );
  }, 5 * 60 * 1000);
  activePurchases.set(owner.accountScope, {
    productId,
    accountScope: owner.accountScope,
    accountBinding: owner.accountBinding,
    resolve: resolvePurchase,
    reject: rejectPurchase,
    timer,
  });

  try {
    await requestPurchase({
      request: IS_PLAY_DISTRIBUTION
        ? {
            google: {
              skus: [productId],
              obfuscatedAccountId: owner.accountBinding,
            },
          }
        : {
            apple: {
              sku: productId,
              appAccountToken: owner.accountBinding,
              andDangerouslyFinishTransactionAutomatically: false,
            },
          },
      type: 'in-app',
    });
  } catch (error: unknown) {
    settleActive(productId, owner.accountScope, active => {
      if (cancelledError(error as {code?: unknown})) {
        active.resolve({
          success: false,
          pending: false,
          cancelled: true,
          coinsAdded: 0,
        });
        return;
      }
      active.reject(error);
    });
  }

  return outcome;
};

export const subscribeNativeStoreCredits = (
  listener: (result: NativeCoinCheckoutResult) => void,
) => {
  creditListeners.add(listener);
  return () => {
    creditListeners.delete(listener);
  };
};

export const nativeStoreChannel = DISTRIBUTION_CHANNEL;
