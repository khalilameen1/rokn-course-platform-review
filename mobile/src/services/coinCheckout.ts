import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import type {CoinPackage} from './api/coinPackageMapper';
import {
  CAN_START_EXTERNAL_CHECKOUT,
  CAN_START_NATIVE_CHECKOUT,
  DISTRIBUTION_CHANNEL,
} from '../constants/distribution';
import {reportClientError} from './operationalTelemetry';
import {requireProductFeature} from './productFeatures';
import {errorCode} from '../utils/errorPayload';
import {
  acknowledgePendingCheckoutReturn,
  savePendingCheckoutReturn,
} from '../navigation/checkoutReturn';
import type {LoginReturnTo} from '../navigation/types';
import {safeLoginReturnTo} from '../navigation/authReturn';
import {
  clearCoinCheckoutAttempt,
  coinCheckoutOwnerKey,
  getOrCreateCoinCheckoutAttempt,
  readCoinCheckoutAttempt,
  reassociateCoinCheckoutAttempt,
  rememberCoinCheckoutOrder,
} from './coinCheckoutAttemptStore';
import {
  emitCoinCheckoutCreditOnce,
  runCoinCheckoutReconciliationSingleFlight,
  runCoinCheckoutSingleFlight,
  subscribeCoinCheckoutCredits,
} from './coinCheckoutCoordinator';
import {
  abandonCoinCheckoutOrder,
  initiateCoinCheckout,
  parseCoinCheckoutFailure,
  reconcileCoinCheckoutOrder,
} from './coinCheckoutHttp';
import {
  reconcileCoinCheckoutPackageSwitch,
  reconcilePendingCoinCheckoutAttempts,
  retireCoinCheckoutAttempt,
} from './coinCheckoutRecovery';
import {
  openCoinCheckoutSurface,
  parseCoinCheckoutCallback,
} from './coinCheckoutProvider';
import type {
  CoinCheckoutAttempt,
  CoinCheckoutResult,
} from './coinCheckoutTypes';

export type {CoinCheckoutResult} from './coinCheckoutTypes';
export {subscribeCoinCheckoutCredits};

const CHECKOUT_ATTEMPT_TTL_MS = 30 * 60 * 1000;

const intentPart = (value: unknown) =>
  encodeURIComponent(value === undefined ? '' : String(value));

const checkoutReturnIntentParts = (value?: LoginReturnTo): unknown[] => {
  const returnTo = safeLoginReturnTo(value);
  if (!returnTo) return ['none'];
  if (returnTo.name === 'CourseDetails') {
    const params = returnTo.params;
    return [
      returnTo.name,
      params.courseId,
      params.openCodeRedemption ? '1' : '0',
      params.openFullTrackUpgrade ? '1' : '0',
      params.openPurchase ? '1' : '0',
      params.purchasePlanCode,
      params.purchaseCouponCode,
      params.resumeAfterPreview ? '1' : '0',
      params.resumeReelId,
    ];
  }
  if (returnTo.name === 'Reels') {
    const params = returnTo.params;
    return [
      returnTo.name,
      params.courseId,
      params.reelId,
      params.lessonId,
      params.projectId,
      params.preview ? '1' : '0',
      params.previewCount,
      params.openCourseChatUpgrade ? '1' : '0',
    ];
  }
  if (returnTo.name === 'Profile') {
    return [returnTo.name, returnTo.params?.tab];
  }
  return [returnTo.name];
};

const checkoutProviderSnapshot = (coinPackage: CoinPackage) => {
  if (DISTRIBUTION_CHANNEL === 'direct') return ['kashier', 'EGP', ''];
  if (DISTRIBUTION_CHANNEL === 'play') {
    return ['google_play', 'store', coinPackage.storeProductIds?.google];
  }
  return ['apple_app_store', 'store', coinPackage.storeProductIds?.apple];
};

const coinCheckoutIntentKey = (
  coinPackage: CoinPackage,
  returnTo?: LoginReturnTo,
) =>
  [
    'v1',
    coinPackage.id,
    coinPackage.coins,
    Math.round(coinPackage.price * 100),
    ...checkoutProviderSnapshot(coinPackage),
    ...checkoutReturnIntentParts(returnTo),
  ]
    .map(intentPart)
    .join('|');

const validCoinPackage = (coinPackage: CoinPackage) => {
  const packageId = Number(coinPackage.id);
  if (
    !Number.isSafeInteger(packageId) ||
    packageId <= 0 ||
    !Number.isSafeInteger(coinPackage.coins) ||
    coinPackage.coins <= 0 ||
    !Number.isFinite(coinPackage.price) ||
    coinPackage.price <= 0
  ) {
    throw new Error('COIN_PACKAGE_CONTRACT_INVALID');
  }
  return packageId;
};

const checkoutTermsChanged = (
  attempt: CoinCheckoutAttempt,
  coinPackage: CoinPackage,
) =>
  attempt.expectedCoins !== coinPackage.coins ||
  Math.round(attempt.expectedPrice * 100) !==
    Math.round(coinPackage.price * 100);

const currentAttemptForPackage = async (
  coinPackage: CoinPackage,
  packageId: number,
  boundary: AccountSessionBoundary,
): Promise<CoinCheckoutAttempt | CoinCheckoutResult | null> => {
  let attempt = await readCoinCheckoutAttempt(packageId, boundary);
  if (attempt && checkoutTermsChanged(attempt, coinPackage)) {
    const retired = await retireCoinCheckoutAttempt(attempt, boundary);
    if (retired.success || retired.pending) return retired;
    attempt = null;
  }

  if (attempt?.orderRef) {
    const previous = await reconcileCoinCheckoutOrder(
      attempt.orderRef,
      boundary,
      1,
    );
    if (previous.approved) {
      await clearCoinCheckoutAttempt(attempt.idempotencyKey, boundary);
      return {
        success: true,
        pending: false,
        cancelled: false,
        coinsAdded: previous.coinsAdded,
        orderRef: attempt.orderRef,
      };
    }
    if (!previous.pending) {
      await clearCoinCheckoutAttempt(attempt.idempotencyKey, boundary);
      attempt = null;
    } else if (
      Date.now() - Date.parse(attempt.createdAt) >=
      CHECKOUT_ATTEMPT_TTL_MS
    ) {
      await clearCoinCheckoutAttempt(attempt.idempotencyKey, boundary);
      attempt = null;
    }
  }
  return attempt;
};

const terminalCheckoutFailureCodes = new Set([
  'checkout_idempotency_conflict',
  'checkout_attempt_closed',
  'checkout_attempt_expired',
  'package_not_available',
  'package_terms_changed',
]);

const runCoinCheckout = async (
  coinPackage: CoinPackage,
  boundary: AccountSessionBoundary,
  allowFreshRetry = true,
): Promise<CoinCheckoutResult> => {
  assertAccountSessionBoundary(boundary);
  const packageId = validCoinPackage(coinPackage);
  if (CAN_START_NATIVE_CHECKOUT) {
    await requireProductFeature('checkout');
    const {purchaseNativeCoinPackage} = await import('./nativeStoreBilling');
    return purchaseNativeCoinPackage(coinPackage);
  }
  if (!CAN_START_EXTERNAL_CHECKOUT) {
    throw new Error('CHECKOUT_DISABLED_FOR_DISTRIBUTION');
  }
  await requireProductFeature('checkout');

  const packageSwitch = await reconcileCoinCheckoutPackageSwitch(
    packageId,
    boundary,
  );
  if (packageSwitch) return packageSwitch;

  const current = await currentAttemptForPackage(
    coinPackage,
    packageId,
    boundary,
  );
  if (current && 'success' in current) return current;
  let attempt = current;
  attempt =
    attempt ??
    (await getOrCreateCoinCheckoutAttempt(
      packageId,
      coinPackage.price,
      coinPackage.coins,
      boundary,
    ));

  let paymentUrl = '';
  let orderRef = '';
  try {
    const initiation = await initiateCoinCheckout(
      {
        packageId,
        expectedAmount: attempt.expectedPrice,
        expectedCoins: attempt.expectedCoins,
        idempotencyKey: attempt.idempotencyKey,
      },
      boundary,
    );
    paymentUrl = initiation.paymentUrl;
    orderRef = initiation.orderRef;
    const remembered = await rememberCoinCheckoutOrder(
      attempt,
      orderRef,
      boundary,
    );
    if (!remembered) {
      // A payable URL must never open unless its server order is durable on
      // this account. Ask the server/provider to retire or settle the order
      // while its reference is still in memory.
      const recovered = await abandonCoinCheckoutOrder(orderRef, boundary);
      return {
        success: recovered.approved,
        pending: recovered.pending,
        cancelled: !recovered.approved && !recovered.pending,
        coinsAdded: recovered.approved ? recovered.coinsAdded : 0,
        orderRef,
      };
    }
  } catch (error: unknown) {
    const failure = parseCoinCheckoutFailure(error);
    if (
      failure.code === 'checkout_attempt_closed' &&
      failure.status === 'approved'
    ) {
      const approvedOrderRef = failure.orderRef || attempt.orderRef || '';
      const approved = await reconcileCoinCheckoutOrder(
        approvedOrderRef,
        boundary,
        1,
      );
      if (!approved.approved) throw error;
      await clearCoinCheckoutAttempt(attempt.idempotencyKey, boundary);
      return {
        success: true,
        pending: false,
        cancelled: false,
        coinsAdded: approved.coinsAdded,
        orderRef: approvedOrderRef,
      };
    }

    if (
      allowFreshRetry &&
      (failure.code === 'checkout_attempt_expired' ||
        (failure.code === 'checkout_attempt_closed' &&
          ['cancelled', 'rejected', 'failed'].includes(failure.status)))
    ) {
      await clearCoinCheckoutAttempt(attempt.idempotencyKey, boundary);
      return runCoinCheckout(coinPackage, boundary, false);
    }

    if (failure.code === 'payment_under_review' && failure.orderRef) {
      if (!failure.packageId || !failure.packageCoins || !failure.amount) {
        throw new Error('PAYMENT_PENDING_CONTRACT_INVALID');
      }
      const remembered = await reassociateCoinCheckoutAttempt(
        attempt,
        {
          packageId: failure.packageId,
          orderRef: failure.orderRef,
          expectedPrice: failure.amount,
          expectedCoins: failure.packageCoins,
        },
        boundary,
      );
      if (!remembered) {
        throw new Error('CHECKOUT_ORDER_REFERENCE_UNAVAILABLE');
      }
      return {
        success: false,
        pending: true,
        cancelled: false,
        coinsAdded: 0,
        orderRef: failure.orderRef,
      };
    }

    if (failure.code === 'pending_checkout_exists' && failure.orderRef) {
      if (!failure.packageId || !failure.packageCoins || !failure.amount) {
        throw new Error('PAYMENT_PENDING_CONTRACT_INVALID');
      }
      if (failure.packageId !== packageId) {
        const remembered = await reassociateCoinCheckoutAttempt(
          attempt,
          {
            packageId: failure.packageId,
            orderRef: failure.orderRef,
            expectedPrice: failure.amount,
            expectedCoins: failure.packageCoins,
          },
          boundary,
        );
        if (!remembered) {
          throw new Error('CHECKOUT_ORDER_REFERENCE_UNAVAILABLE');
        }
        return {
          success: false,
          pending: true,
          cancelled: false,
          coinsAdded: 0,
          orderRef: failure.orderRef,
        };
      }

      const serverTermsChanged =
        failure.packageCoins !== coinPackage.coins ||
        Math.round(failure.amount * 100) !==
          Math.round(coinPackage.price * 100);
      if (serverTermsChanged) {
        const abandoned = await abandonCoinCheckoutOrder(
          failure.orderRef,
          boundary,
        );
        if (!abandoned.pending) {
          await clearCoinCheckoutAttempt(attempt.idempotencyKey, boundary);
        }
        if (abandoned.approved) {
          return {
            success: true,
            pending: false,
            cancelled: false,
            coinsAdded: abandoned.coinsAdded,
            orderRef: failure.orderRef,
          };
        }
        if (abandoned.pending) {
          const remembered = await reassociateCoinCheckoutAttempt(
            attempt,
            {
              packageId,
              orderRef: failure.orderRef,
              expectedPrice: failure.amount,
              expectedCoins: failure.packageCoins,
            },
            boundary,
          );
          if (!remembered) {
            throw new Error('CHECKOUT_ORDER_REFERENCE_UNAVAILABLE');
          }
          return {
            success: false,
            pending: true,
            cancelled: false,
            coinsAdded: 0,
            orderRef: failure.orderRef,
          };
        }
        if (allowFreshRetry) {
          return runCoinCheckout(coinPackage, boundary, false);
        }
        throw new Error('COIN_PACKAGE_TERMS_CHANGED_DURING_CHECKOUT');
      }

      const remembered = await rememberCoinCheckoutOrder(
        attempt,
        failure.orderRef,
        boundary,
      );
      if (!remembered) {
        throw new Error('CHECKOUT_ORDER_REFERENCE_UNAVAILABLE');
      }
      if (failure.paymentUrl) {
        paymentUrl = failure.paymentUrl;
        orderRef = failure.orderRef;
      } else {
        return {
          success: false,
          pending: true,
          cancelled: false,
          coinsAdded: 0,
          orderRef: failure.orderRef,
        };
      }
    }

    if (!paymentUrl || !orderRef) {
      if (terminalCheckoutFailureCodes.has(failure.code)) {
        await clearCoinCheckoutAttempt(attempt.idempotencyKey, boundary);
      }
      throw error;
    }
  }

  try {
    assertAccountSessionBoundary(boundary);
    const callbackUrl = await openCoinCheckoutSurface(paymentUrl);
    assertAccountSessionBoundary(boundary);
    const callback = parseCoinCheckoutCallback(callbackUrl);
    if (!callback.valid) throw new Error('PAYMENT_CALLBACK_INVALID');
    if (callback.orderRef && callback.orderRef !== orderRef) {
      throw new Error('PAYMENT_CALLBACK_ORDER_MISMATCH');
    }

    const status = await reconcileCoinCheckoutOrder(orderRef, boundary);
    if (!status.pending) {
      await clearCoinCheckoutAttempt(attempt.idempotencyKey, boundary);
    }
    if (status.pending) {
      reportClientError(new Error('payment_status_timeout'), {
        source: 'coin_checkout',
        endpoint: 'payment/reconcile',
        requestId: orderRef,
      });
    }
    return {
      success: status.approved,
      pending: status.pending,
      cancelled: false,
      coinsAdded: status.approved ? status.coinsAdded : 0,
      orderRef,
    };
  } catch (error: unknown) {
    if (errorCode(error) === 'CHECKOUT_CANCELLED') {
      assertAccountSessionBoundary(boundary);
      const abandoned = await abandonCoinCheckoutOrder(orderRef, boundary);
      if (!abandoned.pending) {
        await clearCoinCheckoutAttempt(attempt.idempotencyKey, boundary);
      }
      return {
        success: abandoned.approved,
        pending: abandoned.pending,
        cancelled: !abandoned.approved && !abandoned.pending,
        coinsAdded: abandoned.approved ? abandoned.coinsAdded : 0,
        orderRef,
      };
    }
    reportClientError(
      error instanceof Error ? error : new Error('checkout_unknown_error'),
      {
        source: 'coin_checkout',
        endpoint: 'payment/reconcile',
        requestId: orderRef,
      },
    );
    throw error;
  }
};

export const reconcilePendingCoinCheckout = async () => {
  if (!CAN_START_EXTERNAL_CHECKOUT) return null;
  const boundary = await captureAccountSessionBoundary();
  const ownerKey = `${await coinCheckoutOwnerKey(boundary)}:${boundary.epoch}`;
  return runCoinCheckoutReconciliationSingleFlight(ownerKey, async () => {
    const result = await reconcilePendingCoinCheckoutAttempts(boundary);
    assertAccountSessionBoundary(boundary);
    if (result?.success) {
      emitCoinCheckoutCreditOnce(boundary.scope, result);
    }
    return result;
  });
};

export const openCoinCheckout = async (
  coinPackage: CoinPackage,
  options: {returnTo?: LoginReturnTo} = {},
): Promise<CoinCheckoutResult> => {
  const boundary = await captureAccountSessionBoundary();
  const ownerKey = `${await coinCheckoutOwnerKey(boundary)}:${boundary.epoch}`;
  const intentKey = coinCheckoutIntentKey(coinPackage, options.returnTo);
  return runCoinCheckoutSingleFlight(ownerKey, intentKey, async () => {
    const returnClaim = options.returnTo
      ? await savePendingCheckoutReturn(options.returnTo, boundary).catch(
          () => undefined,
        )
      : undefined;
    let result: CoinCheckoutResult | undefined;
    try {
      result = await runCoinCheckout(coinPackage, boundary);
      return result;
    } finally {
      // A pending/unknown provider outcome still owns its interrupted
      // destination. Keep it durable for foreground or cold-start recovery;
      // only a terminal result may consume it here.
      if (returnClaim && result && !result.pending) {
        try {
          assertAccountSessionBoundary(boundary);
          await acknowledgePendingCheckoutReturn(returnClaim);
        } catch {}
      }
    }
  });
};
