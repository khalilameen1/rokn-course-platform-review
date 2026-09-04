import fs from 'fs';
import path from 'path';

const service = (name: string) =>
  fs.readFileSync(
    path.resolve(__dirname, '..', 'src', 'services', name),
    'utf8',
  );

describe('coin checkout architecture', () => {
  it('keeps orchestration independent from HTTP, persistence and provider UI', () => {
    const checkout = service('coinCheckout.ts');
    expect(checkout).not.toMatch(/publicRequest|getItem|saveItem|removeItem/);
    expect(checkout).not.toMatch(/NativeModules|WebBrowser|openAuthSessionAsync/);
    expect(checkout).toContain('initiateCoinCheckout');
    expect(checkout).toContain('coinCheckoutOwnerKey(boundary)');
  });

  it('keeps one canonical v2 ledger and complete immutable package terms', () => {
    const store = service('coinCheckoutAttemptStore.ts');
    expect(store).toContain("'@rokn/coin-checkout-attempt/v2'");
    expect(store).not.toMatch(/\?\s*\[value as|new Date\(0\)/);
    expect(store).toContain('!Number.isFinite(expectedPrice)');
    expect(store).toContain('!Number.isSafeInteger(expectedCoins)');
    expect(store).not.toMatch(/publicRequest|WebBrowser|NativeModules/);
  });

  it('maps only the documented API envelope and provider callback fields', () => {
    const http = service('coinCheckoutHttp.ts');
    const provider = service('coinCheckoutProvider.ts');
    expect(http).toContain('asRecord(envelope?.data)');
    expect(http).not.toMatch(/getItem|saveItem|removeItem|WebBrowser/);
    expect(provider).toContain('const orderRef = params.order_ref');
    expect(provider).not.toContain('params.orderRef');
    expect(provider).not.toMatch(/publicRequest|getItem|saveItem|removeItem/);
  });

  it('keeps account single-flight and credit emission outside HTTP mapping', () => {
    const coordinator = service('coinCheckoutCoordinator.ts');
    expect(coordinator).toContain('runCoinCheckoutSingleFlight');
    expect(coordinator).toContain('emitCoinCheckoutCreditOnce');
    expect(coordinator).not.toMatch(/publicRequest|getItem|saveItem|removeItem/);
  });
});
