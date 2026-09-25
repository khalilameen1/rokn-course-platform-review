import AsyncStorage from '@react-native-async-storage/async-storage';

let mockOwner = {epoch: 0, scope: 'user-a'};

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(
    async (key: string, owner = mockOwner) => `${key}:${owner.scope}`,
  ),
  assertAccountSessionBoundary: jest.fn((owner: typeof mockOwner) => {
    if (owner.epoch !== mockOwner.epoch || owner.scope !== mockOwner.scope)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  }),
  captureAccountSessionBoundary: jest.fn(async () => ({...mockOwner})),
}));

import {
  acknowledgePendingCheckoutReturn,
  claimPendingCheckoutReturn,
  savePendingCheckoutReturn,
} from '../src/navigation/checkoutReturn';

describe('checkout return recovery', () => {
  beforeEach(async () => {
    mockOwner = {epoch: 0, scope: 'user-a'};
    await AsyncStorage.clear();
  });

  it('survives a killed caller and is acknowledged once after navigation', async () => {
    const saved = await savePendingCheckoutReturn({
      name: 'CourseDetails',
      params: {courseId: '52', openPurchase: true, providerUrl: 'hidden'},
    });
    const claimed = await claimPendingCheckoutReturn();

    expect(claimed?.returnTo).toEqual({
      name: 'CourseDetails',
      params: {
        courseId: '52',
        openCodeRedemption: false,
        openFullTrackUpgrade: false,
        openPurchase: true,
        resumeAfterPreview: false,
        resumeReelId: undefined,
      },
    });
    expect(await acknowledgePendingCheckoutReturn(claimed!)).toBe(true);
    expect(await claimPendingCheckoutReturn()).toBeUndefined();
    expect(saved?.receipt).toBe(claimed?.receipt);
  });

  it('does not block a new account behind an old account acknowledgement', async () => {
    const oldClaim = await savePendingCheckoutReturn({name: 'Wallet'});
    const originalRemoveItem = AsyncStorage.removeItem.bind(AsyncStorage);
    let release!: () => void;
    let signalStarted!: () => void;
    const started = new Promise<void>(resolve => (signalStarted = resolve));
    const gate = new Promise<void>(resolve => (release = resolve));
    const removeSpy = jest
      .spyOn(AsyncStorage, 'removeItem')
      .mockImplementationOnce(async key => {
        signalStarted();
        await gate;
        await originalRemoveItem(key);
      });
    const oldOutcome = acknowledgePendingCheckoutReturn(oldClaim!).then(
      value => value,
      (error: unknown) => error,
    );
    let saved = false;
    let next: ReturnType<typeof savePendingCheckoutReturn> | undefined;
    try {
      await started;
      mockOwner = {epoch: 1, scope: 'user-b'};
      next = savePendingCheckoutReturn({
        name: 'CourseDetails',
        params: {courseId: '71'},
      }).then(value => {
        saved = true;
        return value;
      });
      for (let turn = 0; turn < 20; turn += 1) await Promise.resolve();
      expect(saved).toBe(true);
    } finally {
      release();
      await oldOutcome;
      await next;
      removeSpy.mockRestore();
    }
    await expect(oldOutcome).resolves.toEqual(
      new Error('ACCOUNT_CHANGED_DURING_REQUEST'),
    );
    const claimed = await claimPendingCheckoutReturn();
    expect(claimed?.accountBoundary.scope).toBe('user-b');
    expect(claimed?.returnTo).toMatchObject({
      name: 'CourseDetails',
      params: {courseId: '71'},
    });
  });

  it('does not let an older acknowledgement delete a newer checkout return', async () => {
    const older = await savePendingCheckoutReturn({name: 'Wallet'});
    expect(older).toBeDefined();

    const originalRemoveItem = AsyncStorage.removeItem.bind(AsyncStorage);
    let releaseRemove!: () => void;
    let markRemoveStarted!: () => void;
    const removeStarted = new Promise<void>(resolve => {
      markRemoveStarted = resolve;
    });
    const removeGate = new Promise<void>(resolve => {
      releaseRemove = resolve;
    });
    const removeSpy = jest
      .spyOn(AsyncStorage, 'removeItem')
      .mockImplementationOnce(async key => {
        markRemoveStarted();
        await removeGate;
        await originalRemoveItem(key);
      });

    const acknowledgeOlder = acknowledgePendingCheckoutReturn(older!);
    await removeStarted;
    const saveNewer = savePendingCheckoutReturn({
      name: 'CourseDetails',
      params: {courseId: '71', openPurchase: true},
    });
    releaseRemove();
    await expect(acknowledgeOlder).resolves.toBe(true);
    const newer = await saveNewer;
    removeSpy.mockRestore();

    const claimed = await claimPendingCheckoutReturn();
    expect(claimed?.receipt).toBe(newer?.receipt);
    expect(claimed?.returnTo).toMatchObject({
      name: 'CourseDetails',
      params: {courseId: '71', openPurchase: true},
    });
  });
});
