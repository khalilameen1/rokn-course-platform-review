import {
  emitCoinCheckoutCreditOnce,
  runCoinCheckoutReconciliationSingleFlight,
  runCoinCheckoutSingleFlight,
  subscribeCoinCheckoutCredits,
} from '../src/services/coinCheckoutCoordinator';
import type {CoinCheckoutResult} from '../src/services/coinCheckoutTypes';

describe('coin checkout operation ownership', () => {
  const credited = (orderRef: string): CoinCheckoutResult => ({
    success: true,
    pending: false,
    cancelled: false,
    coinsAdded: 200,
    orderRef,
  });

  it('returns recovered credit instead of charging again after a lost response', async () => {
    let finishRecovery!: (value: CoinCheckoutResult) => void;
    const recovered = credited('recovered-before-purchase');
    const recovery = runCoinCheckoutReconciliationSingleFlight(
      'owner-recovered',
      () => new Promise(resolve => (finishRecovery = resolve)),
    );
    const pay = jest.fn(async () => credited('must-not-be-purchased'));
    const checkout = runCoinCheckoutSingleFlight(
      'owner-recovered',
      'course-1',
      pay,
    );

    finishRecovery(recovered);
    await expect(recovery).resolves.toBe(recovered);
    await expect(checkout).resolves.toBe(recovered);
    expect(pay).not.toHaveBeenCalled();
  });

  it('releases a failed checkout so an explicit retry can succeed', async () => {
    const failure = new Error('Connection interrupted');
    await expect(
      runCoinCheckoutSingleFlight('owner-retry', 'course-1', async () => {
        throw failure;
      }),
    ).rejects.toBe(failure);

    const pay = jest.fn(async () => credited('retry-receipt'));
    await expect(
      runCoinCheckoutSingleFlight('owner-retry', 'course-1', pay),
    ).resolves.toEqual(credited('retry-receipt'));
    expect(pay).toHaveBeenCalledTimes(1);
  });

  it('keeps different accounts independent while a checkout is pending', async () => {
    let finishFirst!: (value: CoinCheckoutResult) => void;
    const first = runCoinCheckoutSingleFlight(
      'isolated-owner-1',
      'course-1',
      () => new Promise(resolve => (finishFirst = resolve)),
    );
    const secondPay = jest.fn(async () => credited('owner-2-receipt'));
    await expect(
      runCoinCheckoutSingleFlight('isolated-owner-2', 'course-1', secondPay),
    ).resolves.toEqual(credited('owner-2-receipt'));

    finishFirst(credited('owner-1-receipt'));
    await expect(first).resolves.toEqual(credited('owner-1-receipt'));
    expect(secondPay).toHaveBeenCalledTimes(1);
  });

  it('joins concurrent recovery and releases its lock after failure', async () => {
    let failRecovery!: (error: Error) => void;
    const recovery = runCoinCheckoutReconciliationSingleFlight(
      'owner-recovery-retry',
      () => new Promise((_resolve, reject) => (failRecovery = reject)),
    );
    const duplicateOperation = jest.fn(async () => null);
    const duplicate = runCoinCheckoutReconciliationSingleFlight(
      'owner-recovery-retry',
      duplicateOperation,
    );
    expect(duplicate).toBe(recovery);
    const failure = new Error('Status temporarily unavailable');
    failRecovery(failure);
    await expect(recovery).rejects.toBe(failure);
    expect(duplicateOperation).not.toHaveBeenCalled();

    const retryOperation = jest.fn(async () => credited('recovery-retry'));
    await expect(
      runCoinCheckoutReconciliationSingleFlight(
        'owner-recovery-retry',
        retryOperation,
      ),
    ).resolves.toEqual(credited('recovery-retry'));
    expect(retryOperation).toHaveBeenCalledTimes(1);
  });

  it('notifies once per account and receipt and respects unsubscribe', () => {
    const listener = jest.fn();
    const unsubscribe = subscribeCoinCheckoutCredits(listener);
    const result = credited('notification-receipt');
    try {
      emitCoinCheckoutCreditOnce('notification-owner-1', result);
      emitCoinCheckoutCreditOnce('notification-owner-1', result);
      emitCoinCheckoutCreditOnce('notification-owner-2', result);
      expect(listener.mock.calls).toEqual([
        [result, 'notification-owner-1'],
        [result, 'notification-owner-2'],
      ]);
    } finally {
      unsubscribe();
    }
    emitCoinCheckoutCreditOnce(
      'notification-owner-1',
      credited('after-unsubscribe'),
    );
    expect(listener).toHaveBeenCalledTimes(2);
  });

  it('does not announce pending payments or let a broken observer hide credit', () => {
    const observerFailure = subscribeCoinCheckoutCredits(() => {
      throw new Error('Screen unmounted');
    });
    const listener = jest.fn();
    const unsubscribe = subscribeCoinCheckoutCredits(listener);
    try {
      const result = credited('observer-receipt');
      emitCoinCheckoutCreditOnce('observer-owner', {
        ...result,
        success: false,
        pending: true,
        coinsAdded: 0,
      });
      emitCoinCheckoutCreditOnce('observer-owner', {
        ...result,
        orderRef: undefined,
      });
      expect(listener).not.toHaveBeenCalled();
      emitCoinCheckoutCreditOnce('observer-owner', result);
      expect(listener).toHaveBeenCalledTimes(1);
      expect(listener).toHaveBeenCalledWith(result, 'observer-owner');
    } finally {
      observerFailure();
      unsubscribe();
    }
  });

  it('preserves pending checkout state for foreground retry without racing recovery', async () => {
    let finishCheckout!: (value: CoinCheckoutResult) => void;
    const recoveryOperation = jest.fn(async () => null);
    const checkout = runCoinCheckoutSingleFlight(
      'owner-pending',
      'intent-course-52',
      () =>
        new Promise(resolve => {
          finishCheckout = resolve;
        }),
    );
    const recovery = runCoinCheckoutReconciliationSingleFlight(
      'owner-pending',
      recoveryOperation,
    );
    const pending = {
      success: false,
      pending: true,
      cancelled: false,
      coinsAdded: 0,
      orderRef: 'PKG-PENDING-52',
    };
    finishCheckout(pending);
    await checkout;
    await expect(recovery).resolves.toEqual(pending);
    expect(recoveryOperation).not.toHaveBeenCalled();
  });

  it('preserves a checkout failure so foreground recovery can retry it', async () => {
    let failCheckout!: (error: unknown) => void;
    const recoveryOperation = jest.fn(async () => null);
    const checkout = runCoinCheckoutSingleFlight(
      'owner-network-failure',
      'intent-course-52',
      () =>
        new Promise((_resolve, reject) => {
          failCheckout = reject;
        }),
    );
    const recovery = runCoinCheckoutReconciliationSingleFlight(
      'owner-network-failure',
      recoveryOperation,
    );
    const error = new Error('Network request failed');
    failCheckout(error);
    await expect(checkout).rejects.toBe(error);
    await expect(recovery).rejects.toBe(error);
    expect(recoveryOperation).not.toHaveBeenCalled();
  });

  it('does not run recovery while the provider checkout owns the account', async () => {
    let finishCheckout: ((value: CoinCheckoutResult) => void) | undefined;
    const checkoutOperation = jest.fn(
      () =>
        new Promise<any>(resolve => {
          finishCheckout = resolve;
        }),
    );
    const recoveryOperation = jest.fn(async () => null);

    const checkout = runCoinCheckoutSingleFlight(
      'owner-a',
      'intent-course-52',
      checkoutOperation,
    );
    const recovery = runCoinCheckoutReconciliationSingleFlight(
      'owner-a',
      recoveryOperation,
    );
    finishCheckout?.({
      success: true,
      pending: false,
      cancelled: false,
      coinsAdded: 10,
    });

    await expect(checkout).resolves.toMatchObject({success: true});
    await expect(recovery).resolves.toBeNull();
    expect(recoveryOperation).not.toHaveBeenCalled();
  });

  it('waits for account recovery before starting a new explicit checkout', async () => {
    let finishRecovery: (() => void) | undefined;
    const recoveryOperation = jest.fn(
      () =>
        new Promise<null>(resolve => {
          finishRecovery = () => resolve(null);
        }),
    );
    const checkoutOperation = jest.fn(async () => ({
      success: false,
      pending: false,
      cancelled: true,
      coinsAdded: 0,
    }));

    const recovery = runCoinCheckoutReconciliationSingleFlight(
      'owner-b',
      recoveryOperation,
    );
    const checkout = runCoinCheckoutSingleFlight(
      'owner-b',
      'intent-course-52',
      checkoutOperation,
    );
    expect(checkoutOperation).not.toHaveBeenCalled();

    finishRecovery?.();
    await recovery;
    await checkout;
    expect(checkoutOperation).toHaveBeenCalledTimes(1);
  });

  it('joins only the same intent and rejects a competing course intent', async () => {
    let finishCheckout: ((value: CoinCheckoutResult) => void) | undefined;
    const firstOperation = jest.fn(
      () =>
        new Promise<CoinCheckoutResult>(resolve => {
          finishCheckout = resolve;
        }),
    );
    const duplicateOperation = jest.fn(async () => ({
      success: false,
      pending: false,
      cancelled: true,
      coinsAdded: 0,
    }));
    const competingOperation = jest.fn(async () => ({
      success: false,
      pending: false,
      cancelled: true,
      coinsAdded: 0,
    }));

    const first = runCoinCheckoutSingleFlight(
      'owner-c',
      'intent-course-52',
      firstOperation,
    );
    const duplicate = runCoinCheckoutSingleFlight(
      'owner-c',
      'intent-course-52',
      duplicateOperation,
    );
    const competing = runCoinCheckoutSingleFlight(
      'owner-c',
      'intent-course-71',
      competingOperation,
    );

    await expect(competing).rejects.toMatchObject({
      code: 'coin_checkout_in_progress',
    });
    expect(competingOperation).not.toHaveBeenCalled();
    finishCheckout?.({
      success: true,
      pending: false,
      cancelled: false,
      coinsAdded: 600,
      orderRef: 'PKG-INTENT-52',
    });

    await expect(first).resolves.toMatchObject({orderRef: 'PKG-INTENT-52'});
    await expect(duplicate).resolves.toMatchObject({
      orderRef: 'PKG-INTENT-52',
    });
    expect(firstOperation).toHaveBeenCalledTimes(1);
    expect(duplicateOperation).not.toHaveBeenCalled();
  });
});
