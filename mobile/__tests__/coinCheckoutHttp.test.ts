jest.mock('../src/constants/api', () => ({
  publicRequest: {post: jest.fn()},
}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
}));

import {publicRequest} from '../src/constants/api';
import {
  abandonCoinCheckoutOrder,
  initiateCoinCheckout,
  reconcileCoinCheckoutOrder,
} from '../src/services/coinCheckoutHttp';

const boundary = {epoch: 1, scope: 'user-a'};
const request = {
  packageId: 7,
  expectedAmount: 49,
  expectedCoins: 600,
  idempotencyKey: '11111111-1111-4111-8111-111111111111',
};
const paidCheckout = {
  order_ref: 'PKG-OLDER-PAID',
  order_status: 'approved',
  checkout_state: 'paid',
  financial_status: 'settled',
  transaction_id: 'TXN-OLDER',
  coins_added: 300,
  package: {id: 2, name_ar: 'باقة سابقة', name_en: 'Older package', coins: 300},
};

describe('coin checkout HTTP contract', () => {
  beforeEach(() => jest.clearAllMocks());

  it('accepts the backend paid replay without a URL or a new intent key', async () => {
    (publicRequest.post as jest.Mock).mockResolvedValueOnce({
      data: {data: paidCheckout},
    });

    await expect(initiateCoinCheckout(request, boundary)).resolves.toEqual({
      state: 'paid',
      orderRef: 'PKG-OLDER-PAID',
      coinsAdded: 300,
    });
    expect(publicRequest.post).toHaveBeenCalledTimes(1);
  });

  it('keeps a payable initiation distinct from a settled payment', async () => {
    (publicRequest.post as jest.Mock).mockResolvedValueOnce({
      data: {data: {
        checkout_state: 'created',
        payment_url: 'https://checkout.kashier.io/session',
        order_ref: 'PKG-PAYABLE-01',
        idempotency_key: request.idempotencyKey,
      }},
    });

    await expect(initiateCoinCheckout(request, boundary)).resolves.toEqual({
      state: 'payable',
      paymentUrl: 'https://checkout.kashier.io/session',
      orderRef: 'PKG-PAYABLE-01',
      idempotencyKey: request.idempotencyKey,
    });
  });

  it.each([
    {financial_status: 'review_required'},
    {financial_status: 'reversed'},
    {order_status: 'pending'},
    {order_ref: '../invalid'},
    {coins_added: undefined},
    {coins_added: 0},
    {coins_added: 1.5},
    {coins_added: true},
    {coins_added: Number.MAX_SAFE_INTEGER + 1},
  ])('rejects a malformed or financially ineffective paid response %p', async fields => {
    (publicRequest.post as jest.Mock).mockResolvedValueOnce({
      data: {data: {...paidCheckout, ...fields}},
    });

    await expect(initiateCoinCheckout(request, boundary)).rejects.toThrow(
      'PAYMENT_SESSION_CONTRACT_INVALID',
    );
  });

  it('retires an orphaned local attempt when the server has no order', async () => {
    (publicRequest.post as jest.Mock).mockRejectedValueOnce({
      status: 404,
      data: {code: 'order_not_found', data: null},
    });

    await expect(
      abandonCoinCheckoutOrder('PKG-ORPHANED', boundary),
    ).resolves.toEqual({approved: false, pending: false, coinsAdded: 0});
  });

  it('keeps an order recoverable when provider state cannot be confirmed', async () => {
    (publicRequest.post as jest.Mock).mockRejectedValueOnce({
      code: 'ERR_NETWORK',
    });

    await expect(
      abandonCoinCheckoutOrder('PKG-RECOVERABLE', boundary),
    ).resolves.toEqual({approved: false, pending: true, coinsAdded: 0});
  });

  it('keeps financially reviewed approval pending instead of opening a retry', async () => {
    (publicRequest.post as jest.Mock).mockResolvedValueOnce({
      data: {
        data: {
          status: 'approved',
          financial_status: 'review_required',
          package: {coins: 600},
        },
      },
    });

    await expect(
      reconcileCoinCheckoutOrder('PKG-UNDER-REVIEW', boundary, 1),
    ).resolves.toEqual({approved: false, pending: true, coinsAdded: 0});
  });
});
