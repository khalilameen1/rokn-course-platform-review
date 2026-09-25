const mockScope = jest.fn();
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: (...args: unknown[]) => mockScope(...args),
}));
jest.mock('expo-iap', () => {
  throw new Error('Credit observers must not load the store bridge');
});

describe('native store credit delivery', () => {
  const credited = {
    success: true,
    pending: false,
    cancelled: false,
    coinsAdded: 600,
    orderRef: 'paid-receipt',
  };

  beforeEach(() => {
    jest.resetModules();
    mockScope.mockReset().mockResolvedValue('owner-a');
  });

  it('isolates a failing screen observer and delivers a receipt only once', async () => {
    const {emitNativeStoreCreditOnce, subscribeNativeStoreCredits} =
      require('../src/services/nativeStoreCredits');
    const broken = jest.fn(() => {
      throw new Error('Screen no longer mounted');
    });
    const healthy = jest.fn();
    subscribeNativeStoreCredits(broken);
    subscribeNativeStoreCredits(healthy);

    await expect(
      emitNativeStoreCreditOnce('receipt-1', credited, 'owner-a'),
    ).resolves.toBeUndefined();
    await emitNativeStoreCreditOnce('receipt-1', credited, 'owner-a');
    expect(broken).toHaveBeenCalledTimes(1);
    expect(healthy).toHaveBeenCalledTimes(1);
    expect(healthy).toHaveBeenCalledWith(credited);
  });

  it('allows recovery to retry delivery after a local account lookup failure', async () => {
    const {emitNativeStoreCreditOnce, subscribeNativeStoreCredits} =
      require('../src/services/nativeStoreCredits');
    const listener = jest.fn();
    subscribeNativeStoreCredits(listener);
    mockScope.mockRejectedValueOnce(new Error('Storage unavailable'));
    await expect(
      emitNativeStoreCreditOnce('retry-receipt', credited, 'owner-a'),
    ).resolves.toBeUndefined();
    expect(listener).not.toHaveBeenCalled();
    await emitNativeStoreCreditOnce('retry-receipt', credited, 'owner-a');
    expect(listener).toHaveBeenCalledTimes(1);
  });

  it('checks the current account after the asynchronous lookup without consuming a foreign notification', async () => {
    const {emitNativeStoreCreditOnce, subscribeNativeStoreCredits} =
      require('../src/services/nativeStoreCredits');
    let finishScope!: (scope: string) => void;
    mockScope.mockImplementationOnce(
      () => new Promise<string>(resolve => (finishScope = resolve)),
    );
    const listener = jest.fn();
    subscribeNativeStoreCredits(listener);
    const emission = emitNativeStoreCreditOnce('switched-receipt', credited, 'owner-a');
    finishScope('owner-b');
    await emission;
    expect(listener).not.toHaveBeenCalled();
    await emitNativeStoreCreditOnce('switched-receipt', credited, 'owner-a');
    expect(listener).toHaveBeenCalledTimes(1);
  });

  it('coalesces concurrent delivery and respects unsubscription', async () => {
    const {emitNativeStoreCreditOnce, subscribeNativeStoreCredits} =
      require('../src/services/nativeStoreCredits');
    const listener = jest.fn();
    const unsubscribe = subscribeNativeStoreCredits(listener);
    await Promise.all([
      emitNativeStoreCreditOnce('concurrent-receipt', credited, 'owner-a'),
      emitNativeStoreCreditOnce('concurrent-receipt', credited, 'owner-a'),
    ]);
    expect(listener).toHaveBeenCalledTimes(1);
    unsubscribe();
    await emitNativeStoreCreditOnce('later-receipt', credited, 'owner-a');
    expect(listener).toHaveBeenCalledTimes(1);
  });

  it('never emits pending or rejected outcomes and keeps deduplication memory bounded', async () => {
    const {emitNativeStoreCreditOnce, subscribeNativeStoreCredits} =
      require('../src/services/nativeStoreCredits');
    const listener = jest.fn();
    subscribeNativeStoreCredits(listener);
    await emitNativeStoreCreditOnce(
      'pending', {...credited, success: false, pending: true}, 'owner-a',
    );
    expect(mockScope).not.toHaveBeenCalled();
    for (let index = 0; index < 129; index += 1) {
      await emitNativeStoreCreditOnce('bounded-' + index, credited, 'owner-a');
    }
    await emitNativeStoreCreditOnce('bounded-128', credited, 'owner-a');
    expect(listener).toHaveBeenCalledTimes(129);
    await emitNativeStoreCreditOnce('bounded-0', credited, 'owner-a');
    expect(listener).toHaveBeenCalledTimes(130);
  });
});
