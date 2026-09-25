import type {Purchase} from 'expo-iap';

const mockFinish = jest.fn();
const mockVerify = jest.fn();
const mockScope = jest.fn();
const mockReadBinding = jest.fn();
const mockClearBinding = jest.fn();
let mockPlay = true;

jest.mock('expo-iap', () => ({finishTransaction: (...args: unknown[]) => mockFinish(...args)}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {post: (...args: unknown[]) => mockVerify(...args)},
}));
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: (...args: unknown[]) => mockScope(...args),
}));
jest.mock('../src/constants/distribution', () => ({
  get IS_PLAY_DISTRIBUTION() { return mockPlay; },
  get IS_APP_STORE_DISTRIBUTION() { return !mockPlay; },
}));
jest.mock('../src/services/nativeCourseCheckoutBinding', () => ({
  clearNativeCourseCheckout: (...args: unknown[]) => mockClearBinding(...args),
  readNativeCourseCheckout: (...args: unknown[]) => mockReadBinding(...args),
  validCourseCheckoutId: (value: unknown) => value === '11111111-1111-4111-8111-111111111111',
}));

describe('native receipt verification ownership', () => {
  const purchase = {
    id: 'purchase', store: 'google', productId: 'coins.600',
    purchaseState: 'purchased', purchaseToken: 'signed-token', transactionId: 'transaction-1',
  } as unknown as Purchase;
  const accepted = {
    data: {data: {coins_added: 600, credited: true, finalize_transaction: true}},
  };

  beforeEach(() => {
    jest.resetModules();
    mockPlay = true;
    mockFinish.mockReset().mockResolvedValue(undefined);
    mockVerify.mockReset().mockResolvedValue(accepted);
    mockScope.mockReset().mockResolvedValue('owner-a');
    mockReadBinding.mockReset().mockResolvedValue(undefined);
    mockClearBinding.mockReset().mockResolvedValue(undefined);
  });

  it('leaves pending purchases in the store without verification or finalization', async () => {
    const {verifyAndFinishNativePurchase} = require('../src/services/nativeStoreReceipt');
    await expect(verifyAndFinishNativePurchase(
      {...purchase, purchaseState: 'pending'}, 'owner-a',
    )).resolves.toMatchObject({pending: true, success: false, coinsAdded: 0});
    expect(mockVerify).not.toHaveBeenCalled();
    expect(mockFinish).not.toHaveBeenCalled();
  });

  it('joins one receipt verification and rejects a different account trying to join it', async () => {
    const {verifyAndFinishNativePurchase} = require('../src/services/nativeStoreReceipt');
    let accept!: (value: typeof accepted) => void;
    let posted!: () => void;
    const posting = new Promise<void>(resolve => (posted = resolve));
    mockVerify.mockImplementationOnce(() => {
      posted();
      return new Promise(resolve => (accept = resolve));
    });
    const first = verifyAndFinishNativePurchase(purchase, 'owner-a');
    const duplicate = verifyAndFinishNativePurchase(purchase, 'owner-a');
    await posting;
    await expect(verifyAndFinishNativePurchase(purchase, 'owner-b'))
      .rejects.toThrow('STORE_PURCHASE_ACCOUNT_CHANGED');
    expect(mockFinish).not.toHaveBeenCalled();
    accept(accepted);
    await expect(first).resolves.toMatchObject({success: true});
    await expect(duplicate).resolves.toMatchObject({success: true});
    expect(mockVerify).toHaveBeenCalledTimes(1);
    expect(mockFinish).toHaveBeenCalledTimes(1);
  });

  it('releases a failed verification for retry without finishing or clearing its binding', async () => {
    const {verifyAndFinishNativePurchase} = require('../src/services/nativeStoreReceipt');
    mockVerify.mockRejectedValueOnce(new Error('Server unavailable'));
    await expect(verifyAndFinishNativePurchase(purchase, 'owner-a'))
      .rejects.toThrow('Server unavailable');
    expect(mockFinish).not.toHaveBeenCalled();
    expect(mockClearBinding).not.toHaveBeenCalled();
    await expect(verifyAndFinishNativePurchase(purchase, 'owner-a'))
      .resolves.toMatchObject({success: true, coinsAdded: 600});
    expect(mockVerify).toHaveBeenCalledTimes(2);
  });

  it.each([
    {finalize_transaction: false, coins_added: 600},
    {finalize_transaction: true, coins_added: -1},
    {finalize_transaction: true, coins_added: 0, credited: true},
    {finalize_transaction: true, coins_added: 1.5},
  ])('never finishes an unauthorized or malformed server result %j', async data => {
    const {verifyAndFinishNativePurchase} = require('../src/services/nativeStoreReceipt');
    mockVerify.mockResolvedValue({data: {data}});
    await expect(verifyAndFinishNativePurchase(purchase, 'owner-a')).rejects.toThrow();
    expect(mockFinish).not.toHaveBeenCalled();
    expect(mockClearBinding).not.toHaveBeenCalled();
  });

  it.each([true, false])('respects server finalization for Google only (play=%s)', async play => {
    mockPlay = play;
    const {verifyAndFinishNativePurchase} = require('../src/services/nativeStoreReceipt');
    mockVerify.mockResolvedValue({
      data: {data: {...accepted.data.data, store_finalized: true}},
    });
    await verifyAndFinishNativePurchase(purchase, 'owner-a');
    expect(mockFinish).toHaveBeenCalledTimes(play ? 0 : 1);
    expect(mockVerify).toHaveBeenCalledWith(
      'store-purchases/verify', expect.objectContaining({provider: play ? 'google' : 'apple'}),
    );
  });

  it('keeps the binding after a finish failure and clears it only after matching terminal authorization', async () => {
    const id = '11111111-1111-4111-8111-111111111111';
    const {verifyAndFinishNativePurchase} = require('../src/services/nativeStoreReceipt');
    mockReadBinding.mockResolvedValue(id);
    mockVerify.mockResolvedValue({
      data: {data: {...accepted.data.data, checkout: {id, status: 'completed'}}},
    });
    mockFinish.mockRejectedValueOnce(new Error('Store temporarily unavailable'));
    await expect(verifyAndFinishNativePurchase(purchase, 'owner-a')).rejects.toThrow();
    expect(mockClearBinding).not.toHaveBeenCalled();
    await verifyAndFinishNativePurchase(purchase, 'owner-a');
    expect(mockClearBinding).toHaveBeenCalledWith(purchase.productId, id);
  });
});
