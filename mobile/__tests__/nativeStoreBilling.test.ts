const mockExpoIap = {
  fetchProducts: jest.fn(),
  finishTransaction: jest.fn(),
  getAvailablePurchases: jest.fn(),
  initConnection: jest.fn(),
  purchaseErrorListener: jest.fn(),
  purchaseUpdatedListener: jest.fn(),
  requestPurchase: jest.fn(),
};
const mockApi = {
  get: jest.fn(),
  post: jest.fn(),
};
let mockAccountScope = 'user-a';

jest.mock('expo-iap', () => mockExpoIap);
jest.mock('../src/constants/api', () => ({publicRequest: mockApi}));
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(
    async (key: string) => `${key}:${mockAccountScope}`,
  ),
}));
jest.mock('../src/constants/distribution', () => ({
  DISTRIBUTION_CHANNEL: 'play',
  IS_APP_STORE_DISTRIBUTION: false,
  IS_PLAY_DISTRIBUTION: true,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));

describe('native store billing', () => {
  let purchaseUpdate: (purchase: Record<string, unknown>) => void;
  let purchaseFailure: (error: Record<string, unknown>) => void;

  beforeEach(() => {
    jest.resetModules();
    Object.values(mockExpoIap).forEach(mock => mock.mockReset());
    mockApi.get.mockReset();
    mockApi.post.mockReset();
    mockAccountScope = 'user-a';
    mockExpoIap.initConnection.mockResolvedValue(true);
    mockExpoIap.getAvailablePurchases.mockResolvedValue([]);
    mockExpoIap.purchaseUpdatedListener.mockImplementation(listener => {
      purchaseUpdate = listener;
      return {remove: jest.fn()};
    });
    mockExpoIap.purchaseErrorListener.mockImplementation(listener => {
      purchaseFailure = listener;
      return {remove: jest.fn()};
    });
  });

  it('uses the store-localized product and omits unconfigured packages', async () => {
    mockExpoIap.fetchProducts.mockResolvedValue([
      {
        id: 'rokn.coins.600',
        price: 119.99,
        displayPrice: '١١٩٫٩٩ ج.م.',
        currency: 'EGP',
        platform: 'android',
        type: 'in-app',
      },
    ]);
    const {
      hydrateNativeStorePackages,
    } = require('../src/services/nativeStoreBilling');

    const result = await hydrateNativeStorePackages([
      {
        id: '1',
        coins: 600,
        price: 120,
        label: '600',
        storeProductIds: {google: 'rokn.coins.600'},
      },
      {id: '2', coins: 900, price: 170, label: '900'},
    ]);

    expect(result).toHaveLength(1);
    expect(result[0]).toMatchObject({
      id: '1',
      price: 119.99,
      displayPrice: '١١٩٫٩٩ ج.م.',
    });
    expect(mockExpoIap.fetchProducts).toHaveBeenCalledWith({
      skus: ['rokn.coins.600'],
      type: 'in-app',
    });
  });

  it('finishes a consumable only after the backend verifies and credits it', async () => {
    mockApi.get.mockResolvedValue({
      data: {data: {google_obfuscated_account_id: 'account-binding'}},
    });
    mockApi.post.mockResolvedValue({
      data: {data: {coins_added: 600, finalize_transaction: true}},
    });
    mockExpoIap.requestPurchase.mockImplementation(async () => {
      purchaseUpdate({
        id: 'purchase-1',
        store: 'google',
        productId: 'rokn.coins.600',
        purchaseState: 'purchased',
        purchaseToken: 'store-token',
        transactionId: 'GPA.1111',
        quantity: 1,
        isAutoRenewing: false,
        transactionDate: Date.now(),
      });
      return null;
    });
    const {
      purchaseNativeCoinPackage,
    } = require('../src/services/nativeStoreBilling');

    const result = await purchaseNativeCoinPackage({
      id: '1',
      coins: 600,
      price: 120,
      label: '600',
      storeProductIds: {google: 'rokn.coins.600'},
    });

    expect(mockExpoIap.requestPurchase).toHaveBeenCalledWith({
      request: {
        google: {
          skus: ['rokn.coins.600'],
          obfuscatedAccountId: 'account-binding',
        },
      },
      type: 'in-app',
    });
    expect(mockApi.post).toHaveBeenCalledWith('store-purchases/verify', {
      provider: 'google',
      product_id: 'rokn.coins.600',
      purchase_token: 'store-token',
      transaction_id: 'GPA.1111',
    });
    expect(mockExpoIap.finishTransaction).toHaveBeenCalledWith({
      purchase: expect.objectContaining({purchaseToken: 'store-token'}),
      isConsumable: true,
    });
    expect(result).toMatchObject({success: true, coinsAdded: 600});
    expect(mockApi.post.mock.invocationCallOrder[0]).toBeLessThan(
      mockExpoIap.finishTransaction.mock.invocationCallOrder[0],
    );
  });

  it('never finishes the store transaction when server verification fails', async () => {
    mockApi.get.mockResolvedValue({
      data: {data: {google_obfuscated_account_id: 'account-binding'}},
    });
    mockApi.post.mockRejectedValue(new Error('verification unavailable'));
    mockExpoIap.requestPurchase.mockImplementation(async () => {
      purchaseUpdate({
        id: 'purchase-2',
        store: 'google',
        productId: 'rokn.coins.600',
        purchaseState: 'purchased',
        purchaseToken: 'store-token-two',
        transactionId: 'GPA.2222',
        quantity: 1,
        isAutoRenewing: false,
        transactionDate: Date.now(),
      });
      return null;
    });
    const {
      purchaseNativeCoinPackage,
    } = require('../src/services/nativeStoreBilling');

    await expect(
      purchaseNativeCoinPackage({
        id: '1',
        coins: 600,
        price: 120,
        label: '600',
        storeProductIds: {google: 'rokn.coins.600'},
      }),
    ).rejects.toThrow('verification unavailable');
    expect(mockExpoIap.finishTransaction).not.toHaveBeenCalled();
  });

  it('does not let a pending product lock every other coin package', async () => {
    mockExpoIap.getAvailablePurchases.mockResolvedValue([
      {
        id: 'pending-old-package',
        store: 'google',
        productId: 'rokn.coins.600',
        purchaseState: 'pending',
        purchaseToken: 'pending-token',
      },
    ]);
    mockApi.get.mockResolvedValue({
      data: {data: {google_obfuscated_account_id: 'account-binding'}},
    });
    mockApi.post.mockResolvedValue({
      data: {data: {coins_added: 1200, finalize_transaction: true}},
    });
    mockExpoIap.requestPurchase.mockImplementation(async () => {
      purchaseUpdate({
        id: 'purchase-new-package',
        store: 'google',
        productId: 'rokn.coins.1200',
        purchaseState: 'purchased',
        purchaseToken: 'new-package-token',
        transactionId: 'GPA.3333',
        quantity: 1,
        isAutoRenewing: false,
        transactionDate: Date.now(),
      });
      return null;
    });
    const {
      purchaseNativeCoinPackage,
    } = require('../src/services/nativeStoreBilling');

    await expect(
      purchaseNativeCoinPackage({
        id: '2',
        coins: 1200,
        price: 200,
        label: '1200',
        storeProductIds: {google: 'rokn.coins.1200'},
      }),
    ).resolves.toMatchObject({success: true, coinsAdded: 1200});
    expect(mockExpoIap.requestPurchase).toHaveBeenCalled();
  });

  it('treats a store sheet dismissal as cancellation rather than payment failure', async () => {
    mockApi.get.mockResolvedValue({
      data: {data: {google_obfuscated_account_id: 'account-binding'}},
    });
    mockExpoIap.requestPurchase.mockRejectedValue({code: 'user-cancelled'});
    const {
      purchaseNativeCoinPackage,
    } = require('../src/services/nativeStoreBilling');

    await expect(
      purchaseNativeCoinPackage({
        id: '1',
        coins: 600,
        price: 120,
        label: '600',
        storeProductIds: {google: 'rokn.coins.600'},
      }),
    ).resolves.toMatchObject({
      success: false,
      pending: false,
      cancelled: true,
    });
  });

  it('does not hide a store user error as if the learner cancelled', async () => {
    mockApi.get.mockResolvedValue({
      data: {data: {google_obfuscated_account_id: 'account-binding'}},
    });
    mockExpoIap.requestPurchase.mockRejectedValue({code: 'user-error'});
    const {
      purchaseNativeCoinPackage,
    } = require('../src/services/nativeStoreBilling');

    await expect(
      purchaseNativeCoinPackage({
        id: '1',
        coins: 600,
        price: 120,
        label: '600',
        storeProductIds: {google: 'rokn.coins.600'},
      }),
    ).rejects.toMatchObject({code: 'user-error'});
  });

  it.each(['pending', 'deferred-payment'])(
    'keeps a %s checkout pending instead of reporting a failure',
    async code => {
      mockApi.get.mockResolvedValue({
        data: {data: {google_obfuscated_account_id: 'account-binding'}},
      });
      mockExpoIap.requestPurchase.mockRejectedValue({code});
      const {
        purchaseNativeCoinPackage,
      } = require('../src/services/nativeStoreBilling');

      await expect(
        purchaseNativeCoinPackage({
          id: '1',
          coins: 600,
          price: 120,
          label: '600',
          storeProductIds: {google: 'rokn.coins.600'},
        }),
      ).resolves.toMatchObject({
        success: false,
        pending: true,
        cancelled: false,
      });
    },
  );

  it('keeps an asynchronous deferred-payment callback pending', async () => {
    mockApi.get.mockResolvedValue({
      data: {data: {google_obfuscated_account_id: 'account-binding'}},
    });
    mockExpoIap.requestPurchase.mockImplementation(async () => {
      purchaseFailure({
        code: 'deferred-payment',
        productId: 'rokn.coins.600',
      });
      return null;
    });
    const {
      purchaseNativeCoinPackage,
    } = require('../src/services/nativeStoreBilling');

    await expect(
      purchaseNativeCoinPackage({
        id: '1',
        coins: 600,
        price: 120,
        label: '600',
        storeProductIds: {google: 'rokn.coins.600'},
      }),
    ).resolves.toMatchObject({
      success: false,
      pending: true,
      cancelled: false,
    });
  });

  it('retries listener installation after a partial native setup failure', async () => {
    const removePurchaseUpdates = jest.fn();
    mockExpoIap.purchaseUpdatedListener
      .mockReturnValueOnce({remove: removePurchaseUpdates})
      .mockImplementation(listener => {
        purchaseUpdate = listener;
        return {remove: jest.fn()};
      });
    mockExpoIap.purchaseErrorListener
      .mockImplementationOnce(() => {
        throw new Error('listener setup failed');
      })
      .mockReturnValue({remove: jest.fn()});
    const {
      reconcileNativeStorePurchases,
    } = require('../src/services/nativeStoreBilling');

    await expect(reconcileNativeStorePurchases()).rejects.toThrow(
      'listener setup failed',
    );
    await expect(reconcileNativeStorePurchases()).resolves.toEqual({
      pending: false,
      pendingProductIds: [],
      reconciled: 0,
    });

    expect(removePurchaseUpdates).toHaveBeenCalledTimes(1);
    expect(mockExpoIap.initConnection).toHaveBeenCalledTimes(2);
    expect(mockExpoIap.purchaseUpdatedListener).toHaveBeenCalledTimes(2);
    expect(mockExpoIap.purchaseErrorListener).toHaveBeenCalledTimes(2);
  });

  it('leaves store receipts untouched while Rokn is in guest mode', async () => {
    mockApi.get.mockRejectedValue({response: {status: 401}});
    mockExpoIap.getAvailablePurchases.mockResolvedValue([
      {
        id: 'guest-visible-receipt',
        store: 'google',
        productId: 'rokn.coins.600',
        purchaseState: 'purchased',
        purchaseToken: 'receipt-owned-by-user-a',
        obfuscatedAccountIdAndroid: 'binding-user-a',
      },
    ]);
    const {
      reconcileNativeStorePurchases,
    } = require('../src/services/nativeStoreBilling');

    await expect(reconcileNativeStorePurchases()).resolves.toEqual({
      pending: false,
      pendingProductIds: [],
      reconciled: 0,
    });
    expect(mockApi.post).not.toHaveBeenCalled();
    expect(mockExpoIap.finishTransaction).not.toHaveBeenCalled();
  });

  it('does not let an active purchase from the previous Rokn account block the new account', async () => {
    mockApi.get.mockImplementation(async () => ({
      data: {
        data: {google_obfuscated_account_id: `binding-${mockAccountScope}`},
      },
    }));
    mockApi.post.mockResolvedValue({
      data: {data: {coins_added: 600, finalize_transaction: true}},
    });
    let requestNumber = 0;
    let markFirstPurchaseStarted!: () => void;
    const firstPurchaseStarted = new Promise<void>(resolve => {
      markFirstPurchaseStarted = resolve;
    });
    mockExpoIap.requestPurchase.mockImplementation(async () => {
      requestNumber += 1;
      if (requestNumber === 1) markFirstPurchaseStarted();
      if (requestNumber === 2) {
        purchaseUpdate({
          id: 'purchase-user-b',
          store: 'google',
          productId: 'rokn.coins.600',
          purchaseState: 'purchased',
          purchaseToken: 'token-user-b',
          transactionId: 'GPA.B',
          obfuscatedAccountIdAndroid: 'binding-user-b',
        });
      }
      return null;
    });
    const {
      purchaseNativeCoinPackage,
    } = require('../src/services/nativeStoreBilling');
    const coinPackage = {
      id: '1',
      coins: 600,
      price: 120,
      label: '600',
      storeProductIds: {google: 'rokn.coins.600'},
    };

    const userAOutcome = purchaseNativeCoinPackage(coinPackage);
    await firstPurchaseStarted;
    mockAccountScope = 'user-b';
    await expect(purchaseNativeCoinPackage(coinPackage)).resolves.toMatchObject(
      {
        success: true,
        coinsAdded: 600,
      },
    );

    mockAccountScope = 'user-a';
    purchaseUpdate({
      id: 'purchase-user-a',
      store: 'google',
      productId: 'rokn.coins.600',
      purchaseState: 'purchased',
      purchaseToken: 'token-user-a',
      transactionId: 'GPA.A',
      obfuscatedAccountIdAndroid: 'binding-user-a',
    });
    await expect(userAOutcome).resolves.toMatchObject({
      success: true,
      coinsAdded: 600,
    });
    expect(mockExpoIap.requestPurchase).toHaveBeenCalledTimes(2);
  });

  it('keeps a foreign receipt for its owner without blocking or finishing it under the current account', async () => {
    mockAccountScope = 'user-b';
    mockApi.get.mockImplementation(async () => ({
      data: {
        data: {google_obfuscated_account_id: `binding-${mockAccountScope}`},
      },
    }));
    mockApi.post.mockResolvedValue({
      data: {data: {coins_added: 600, finalize_transaction: true}},
    });
    mockExpoIap.getAvailablePurchases.mockResolvedValue([
      {
        id: 'foreign-receipt',
        store: 'google',
        productId: 'rokn.coins.600',
        purchaseState: 'purchased',
        purchaseToken: 'token-user-a',
        transactionId: 'GPA.A',
        obfuscatedAccountIdAndroid: 'binding-user-a',
      },
    ]);
    const {
      reconcileNativeStorePurchases,
    } = require('../src/services/nativeStoreBilling');

    await expect(reconcileNativeStorePurchases()).resolves.toEqual({
      pending: false,
      pendingProductIds: [],
      reconciled: 0,
    });
    expect(mockApi.post).not.toHaveBeenCalled();
    expect(mockExpoIap.finishTransaction).not.toHaveBeenCalled();

    mockAccountScope = 'user-a';
    await expect(reconcileNativeStorePurchases()).resolves.toMatchObject({
      pending: false,
      reconciled: 1,
    });
    expect(mockApi.post).toHaveBeenCalledTimes(1);
    expect(mockExpoIap.finishTransaction).toHaveBeenCalledTimes(1);
  });
});
