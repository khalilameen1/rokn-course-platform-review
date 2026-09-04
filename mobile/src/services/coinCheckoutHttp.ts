import {publicRequest} from '../constants/api';
import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {asRecord, errorCode, errorPayload, errorStatus} from '../utils/errorPayload';
import type {CoinCheckoutOrderStatus} from './coinCheckoutTypes';

type CheckoutInitiation = {
  paymentUrl: string;
  orderRef: string;
  idempotencyKey: string;
};

export type CheckoutFailure = {
  code: string;
  orderRef: string;
  status: string;
  paymentUrl: string;
  amount?: number;
  packageId?: number;
  packageCoins?: number;
};

const ORDER_REFERENCE_PATTERN = /^[a-zA-Z0-9_-]{8,100}$/;

const responseData = (response: unknown, contractError: string) => {
  const responseRecord = asRecord(response);
  const envelope = asRecord(responseRecord?.data);
  const data = asRecord(envelope?.data);
  if (!data) throw new Error(contractError);
  return data;
};

const finitePositiveNumber = (value: unknown) => {
  const number = Number(value);
  return Number.isFinite(number) && number > 0 ? number : undefined;
};

const positiveInteger = (value: unknown) => {
  const number = Number(value);
  return Number.isSafeInteger(number) && number > 0 ? number : undefined;
};

export const parseCoinCheckoutFailure = (error: unknown): CheckoutFailure => {
  const envelope = errorPayload(error);
  const data = asRecord(envelope.data);
  const packageData = asRecord(data?.package);
  return {
    code: errorCode(error),
    orderRef: String(data?.order_ref || '').trim(),
    status: String(data?.status || '').toLowerCase(),
    paymentUrl: String(data?.payment_url || '').trim(),
    amount: finitePositiveNumber(data?.amount),
    packageId: positiveInteger(packageData?.id),
    packageCoins: positiveInteger(packageData?.coins),
  };
};

export const initiateCoinCheckout = async (
  request: {
    packageId: number;
    expectedAmount: number;
    expectedCoins: number;
    idempotencyKey: string;
  },
  boundary: AccountSessionBoundary,
): Promise<CheckoutInitiation> => {
  assertAccountSessionBoundary(boundary);
  const response = await publicRequest.post(
    'payment/initiate',
    {
      package_id: request.packageId,
      expected_amount: request.expectedAmount,
      expected_coins: request.expectedCoins,
      idempotency_key: request.idempotencyKey,
    },
    {headers: {'Idempotency-Key': request.idempotencyKey}},
  );
  assertAccountSessionBoundary(boundary);
  const data = responseData(response, 'PAYMENT_SESSION_CONTRACT_INVALID');
  const paymentUrl = String(data.payment_url || '').trim();
  const orderRef = String(data.order_ref || '').trim();
  const idempotencyKey = String(data.idempotency_key || '').toLowerCase();
  if (
    !/^https:\/\/checkout\.kashier\.io(?:\/|\?|$)/i.test(paymentUrl) ||
    !ORDER_REFERENCE_PATTERN.test(orderRef) ||
    idempotencyKey !== request.idempotencyKey
  ) {
    throw new Error('PAYMENT_SESSION_CONTRACT_INVALID');
  }
  return {paymentUrl, orderRef, idempotencyKey};
};

export const reconcileCoinCheckoutOrder = async (
  orderRef: string,
  boundary: AccountSessionBoundary,
  attempts = 4,
): Promise<CoinCheckoutOrderStatus> => {
  const normalizedOrderRef = String(orderRef).trim();
  if (!ORDER_REFERENCE_PATTERN.test(normalizedOrderRef)) {
    throw new Error('PAYMENT_ORDER_REFERENCE_INVALID');
  }

  for (let attempt = 0; attempt < attempts; attempt += 1) {
    assertAccountSessionBoundary(boundary);
    let response: unknown;
    try {
      response = await publicRequest.post(
        `payment/reconcile/${encodeURIComponent(normalizedOrderRef)}`,
      );
      assertAccountSessionBoundary(boundary);
    } catch (error: unknown) {
      assertAccountSessionBoundary(boundary);
      const status = errorStatus(error);
      const code = errorCode(error);
      if (status === 404 || code === 'order_not_found') {
        return {approved: false, pending: false, coinsAdded: 0};
      }
      if (
        status >= 400 &&
        status < 500 &&
        ![408, 409, 425, 429].includes(status)
      ) {
        throw error;
      }
      if (attempt + 1 < attempts) {
        await new Promise<void>(resolve =>
          setTimeout(resolve, 900 + attempt * 350),
        );
      }
      continue;
    }

    const data = responseData(response, 'PAYMENT_STATUS_CONTRACT_INVALID');
    const status = String(data.status || '').toLowerCase();
    const financialStatus = String(data.financial_status || '').toLowerCase();
    const packageData = asRecord(data.package);
    if (status === 'approved' && financialStatus === 'settled') {
      const coinsAdded = positiveInteger(packageData?.coins);
      if (!coinsAdded) throw new Error('PAYMENT_STATUS_CONTRACT_INVALID');
      return {approved: true, pending: false, coinsAdded};
    }
    if (
      (status === 'approved' &&
        [
          'review_required',
          'refunded',
          'chargeback',
          'reversed',
          'partially_recovered',
        ].includes(financialStatus)) ||
      ['failed', 'cancelled', 'rejected'].includes(status)
    ) {
      return {approved: false, pending: false, coinsAdded: 0};
    }
    if (!status || !financialStatus) {
      throw new Error('PAYMENT_STATUS_CONTRACT_INVALID');
    }
    if (attempt + 1 < attempts) {
      await new Promise<void>(resolve =>
        setTimeout(resolve, 900 + attempt * 350),
      );
    }
  }
  return {approved: false, pending: true, coinsAdded: 0};
};

export const abandonCoinCheckoutOrder = async (
  orderRef: string,
  boundary: AccountSessionBoundary,
): Promise<CoinCheckoutOrderStatus> => {
  assertAccountSessionBoundary(boundary);
  try {
    const response = await publicRequest.post(
      `payment/abandon/${encodeURIComponent(orderRef)}`,
    );
    assertAccountSessionBoundary(boundary);
    const data = responseData(response, 'PAYMENT_ABANDON_CONTRACT_INVALID');
    const status = String(data.status || '').toLowerCase();
    const financialStatus = String(data.financial_status || '').toLowerCase();
    const coinsAdded = positiveInteger(data.coins_added) ?? 0;
    const approved = status === 'approved' && financialStatus === 'settled';
    const terminal = ['cancelled', 'rejected', 'failed'].includes(status);
    return {
      approved,
      pending: !approved && !terminal,
      coinsAdded,
    };
  } catch (error: unknown) {
    assertAccountSessionBoundary(boundary);
    const status = errorStatus(error);
    const code = errorCode(error);
    if (status === 404 || code === 'order_not_found') {
      return {approved: false, pending: false, coinsAdded: 0};
    }
    if (
      status >= 400 &&
      status < 500 &&
      ![408, 409, 425, 429].includes(status)
    ) {
      throw error;
    }
    return {approved: false, pending: true, coinsAdded: 0};
  }
};
