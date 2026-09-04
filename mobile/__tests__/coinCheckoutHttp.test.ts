jest.mock('../src/constants/api', () => ({
  publicRequest: {post: jest.fn()},
}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
}));

import {publicRequest} from '../src/constants/api';
import {abandonCoinCheckoutOrder} from '../src/services/coinCheckoutHttp';

const boundary = {epoch: 1, scope: 'user-a'};

describe('coin checkout HTTP contract', () => {
  beforeEach(() => jest.clearAllMocks());

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
});
