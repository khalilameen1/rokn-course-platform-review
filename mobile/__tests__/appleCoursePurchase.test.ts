import AsyncStorage from '@react-native-async-storage/async-storage';

const mockBinding = '22222222-2222-4222-8222-222222222222';
const mockCheckout = '11111111-1111-4111-8111-111111111111';
const mockSdk = {
  initConnection: jest.fn(),
  getAvailablePurchases: jest.fn(),
  fetchProducts: jest.fn(),
  requestPurchase: jest.fn(),
  purchaseUpdatedListener: jest.fn(),
  purchaseErrorListener: jest.fn(),
  finishTransaction: jest.fn(),
};
const mockApi = {get: jest.fn(), post: jest.fn()};
jest.mock('expo-iap', () => mockSdk);
jest.mock('../src/constants/api', () => ({publicRequest: mockApi}));
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (key: string) => `${key}:user-14`,
}));
jest.mock('../src/constants/distribution', () => ({
  DISTRIBUTION_CHANNEL: 'appstore',
  IS_APP_STORE_DISTRIBUTION: true,
  IS_PLAY_DISTRIBUTION: false,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));

const coinPackage = {
  id: '4',
  coins: 900,
  price: 11.11,
  label: '900',
  storeProductIds: {google: 'rokn.coins.900', apple: 'rokn.coins.900'},
};
const purchase = {
  id: 'apple-transaction-1',
  transactionId: 'apple-transaction-1',
  store: 'apple',
  productId: 'rokn.coins.900',
  purchaseState: 'purchased',
  purchaseToken: 'signed-apple-jws',
  appAccountToken: mockBinding,
};

describe('Apple course purchase through the existing StoreKit adapter', () => {
  let publish: (value: Record<string, unknown>) => void;
  beforeEach(async () => {
    await AsyncStorage.clear();
    jest.resetModules();
    Object.values(mockSdk).forEach(mock => mock.mockReset());
    Object.values(mockApi).forEach(mock => mock.mockReset());
    mockSdk.initConnection.mockResolvedValue(true);
    mockSdk.getAvailablePurchases.mockResolvedValue([]);
    mockSdk.purchaseUpdatedListener.mockImplementation(listener => {
      publish = listener;
      return {remove: jest.fn()};
    });
    mockSdk.purchaseErrorListener.mockReturnValue({remove: jest.fn()});
    mockSdk.finishTransaction.mockResolvedValue(undefined);
    mockApi.get.mockResolvedValue({
      data: {data: {apple_app_account_token: mockBinding}},
    });
  });

  it('uses the Apple product price and localized currency, not a direct-payment rate', async () => {
    mockSdk.fetchProducts.mockResolvedValue([
      {
        id: 'rokn.coins.900',
        price: 19.99,
        displayPrice: 'EGP 19.99',
        currency: 'EGP',
      },
    ]);
    const {
      hydrateNativeStorePackages,
    } = require('../src/services/nativeStoreBilling');
    await expect(hydrateNativeStorePackages([coinPackage])).resolves.toEqual([
      {
        ...coinPackage,
        price: 19.99,
        displayPrice: 'EGP 19.99',
        currency: 'EGP',
      },
    ]);
    expect(mockSdk.fetchProducts).toHaveBeenCalledWith({
      skus: ['rokn.coins.900'],
      type: 'in-app',
    });
    expect(mockApi.post).not.toHaveBeenCalled();
  });

  it('binds the Apple transaction to the account and course before verification and finalization', async () => {
    let releaseVerification!: (value: unknown) => void;
    const verification = new Promise(resolve => {
      releaseVerification = resolve;
    });
    mockApi.post.mockReturnValue(verification);
    mockSdk.requestPurchase.mockImplementation(async () => {
      publish(purchase);
    });
    const {
      purchaseNativeCoinPackage,
    } = require('../src/services/nativeStoreBilling');
    const outcome = purchaseNativeCoinPackage(coinPackage, {
      courseCheckoutId: mockCheckout,
    });
    for (let i = 0; i < 60 && !mockApi.post.mock.calls.length; i += 1) {
      await Promise.resolve();
    }
    expect(mockSdk.requestPurchase).toHaveBeenCalledWith({
      request: {
        apple: {
          sku: 'rokn.coins.900',
          appAccountToken: mockBinding,
          andDangerouslyFinishTransactionAutomatically: false,
        },
      },
      type: 'in-app',
    });
    expect(mockApi.post).toHaveBeenCalledWith('store-purchases/verify', {
      provider: 'apple',
      product_id: 'rokn.coins.900',
      purchase_token: 'signed-apple-jws',
      transaction_id: 'apple-transaction-1',
      checkout_id: mockCheckout,
    });
    expect(mockSdk.finishTransaction).not.toHaveBeenCalled();
    releaseVerification({
      data: {
        data: {
          coins_added: 900,
          credited: true,
          finalize_transaction: true,
        },
      },
    });
    await expect(outcome).resolves.toMatchObject({
      success: true,
      coinsAdded: 900,
    });
    expect(mockSdk.finishTransaction).toHaveBeenCalledWith({
      purchase,
      isConsumable: true,
    });
    expect(mockSdk.finishTransaction).toHaveBeenCalledTimes(1);
  });

  it('leaves an unverified Apple transaction recoverable instead of finalizing it', async () => {
    mockApi.post.mockRejectedValue(new Error('verification unavailable'));
    mockSdk.requestPurchase.mockImplementation(async () => {
      publish(purchase);
    });
    const {
      purchaseNativeCoinPackage,
    } = require('../src/services/nativeStoreBilling');
    await expect(
      purchaseNativeCoinPackage(coinPackage, {courseCheckoutId: mockCheckout}),
    ).rejects.toThrow('verification unavailable');
    expect(mockSdk.finishTransaction).not.toHaveBeenCalled();
  });
});
