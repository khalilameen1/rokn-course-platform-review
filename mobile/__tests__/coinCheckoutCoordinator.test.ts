import {
  runCoinCheckoutReconciliationSingleFlight,
  runCoinCheckoutSingleFlight,
} from '../src/services/coinCheckoutCoordinator';
import type {CoinCheckoutResult} from '../src/services/coinCheckoutTypes';

describe('coin checkout operation ownership', () => {
  it('does not run recovery while the provider checkout owns the account', async () => {
    let finishCheckout: ((value: CoinCheckoutResult) => void) | undefined;
    const checkoutOperation = jest.fn(
      () =>
        new Promise<any>(resolve => {
          finishCheckout = resolve;
        }),
    );
    const recoveryOperation = jest.fn(async () => null);

    const checkout = runCoinCheckoutSingleFlight('owner-a', checkoutOperation);
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
    const checkout = runCoinCheckoutSingleFlight('owner-b', checkoutOperation);
    expect(checkoutOperation).not.toHaveBeenCalled();

    finishRecovery?.();
    await recovery;
    await checkout;
    expect(checkoutOperation).toHaveBeenCalledTimes(1);
  });
});
