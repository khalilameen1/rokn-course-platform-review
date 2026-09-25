import {
  fetchProducts,
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
import {payload} from './api/common';
import {reportClientError} from './operationalTelemetry';
import {errorCode} from '../utils/errorPayload';
import {
  clearNativeCourseCheckout,
  rememberNativeCourseCheckout,
} from './nativeCourseCheckoutBinding';
import type {CoinCheckoutResult} from './coinCheckoutTypes';
import {emitNativeStoreCreditOnce} from './nativeStoreCredits';
import {
  nativePurchaseKey,
  verifyAndFinishNativePurchase,
} from './nativeStoreReceipt';

type StoreBillingContext = {
  google_obfuscated_account_id?: unknown;
  apple_app_account_token?: unknown;
};

type ActivePurchase = {
  productId: string;
  accountScope: string;
  accountBinding: string;
  resolve: (value: CoinCheckoutResult) => void;
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
const reconciliationFlights = new Map<
  string,
  Promise<{pending: boolean; pendingProductIds: string[]; reconciled: number}>
>();
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
    try {
      const accountScope = await accountScopedStorageKey(
        '@rokn/native-store-reconciliation/v1',
      );
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

const cancelledError = (error: {code?: unknown}) => {
  const code = String(error.code || '')
    .trim()
    .toLowerCase()
    .replace(/_/g, '-');
  return [
    'user-cancelled',
    'user-canceled',
    'e-user-cancelled',
    'e-user-canceled',
    'cancelled',
    'canceled',
  ].includes(code);
};

const pendingError = (error: {code?: unknown}) =>
  ['pending', 'deferred-payment'].includes(
    String(error.code || '')
      .trim()
      .toLowerCase(),
  );

const pendingResult = (): CoinCheckoutResult => ({
  success: false,
  pending: true,
  cancelled: false,
  coinsAdded: 0,
});

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

const handlePurchaseUpdate = async (purchase: Purchase) => {
  const owner = await currentStoreBillingOwner();
  if (!owner || !purchaseBelongsTo(purchase, owner)) return;
  try {
    const result = await verifyAndFinishNativePurchase(
      purchase,
      owner.accountScope,
    );
    void emitNativeStoreCreditOnce(
      nativePurchaseKey(purchase),
      result,
      owner.accountScope,
    );
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
  let purchaseUpdates: {remove: () => void} | undefined;
  let purchaseErrors: {remove: () => void} | undefined;
  try {
    purchaseUpdates = purchaseUpdatedListener(purchase => {
      void handlePurchaseUpdate(purchase);
    });
    purchaseErrors = purchaseErrorListener(error => {
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
      if (pendingError(error)) {
        current.resolve(pendingResult());
        return;
      }
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
        new Error(
          String(error.code || error.message || 'STORE_PURCHASE_FAILED'),
        ),
      );
    });
    listenersReady = true;
  } catch (error) {
    purchaseUpdates?.remove();
    purchaseErrors?.remove();
    listenersReady = false;
    throw error;
  }
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
        const result = await verifyAndFinishNativePurchase(
          purchase,
          owner.accountScope,
        );
        void emitNativeStoreCreditOnce(
          nativePurchaseKey(purchase),
          result,
          owner.accountScope,
        );
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
    if (
      !Number.isFinite(Number(product.price)) ||
      Number(product.price) <= 0 ||
      !product.displayPrice
    )
      return [];
    return [
      {
        ...coinPackage,
        price: Number(product.price),
        displayPrice: product.displayPrice,
        currency: product.currency,
      },
    ];
  });
};

export const purchaseNativeCoinPackage = async (
  coinPackage: CoinPackage,
  options: {courseCheckoutId?: string} = {},
): Promise<CoinCheckoutResult> => {
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
  if (options.courseCheckoutId) {
    await rememberNativeCourseCheckout(productId, options.courseCheckoutId);
  }

  let resolvePurchase!: (value: CoinCheckoutResult) => void;
  let rejectPurchase!: (reason?: unknown) => void;
  const outcome = new Promise<CoinCheckoutResult>((resolve, reject) => {
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
              ...(options.courseCheckoutId
                ? {obfuscatedProfileId: options.courseCheckoutId}
                : {}),
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
      if (pendingError(error as {code?: unknown})) {
        active.resolve(pendingResult());
        return;
      }
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

  const result = await outcome;
  if (result.cancelled && options.courseCheckoutId)
    await clearNativeCourseCheckout(productId, options.courseCheckoutId);
  return result;
};

export const nativeStoreChannel = DISTRIBUTION_CHANNEL;
