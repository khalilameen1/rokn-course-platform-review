jest.mock('expo-web-browser', () => ({
  openAuthSessionAsync: jest.fn(),
}));

import {parseCoinCheckoutCallback} from '../src/services/coinCheckoutProvider';

describe('coin checkout provider return', () => {
  it('accepts the documented callback fields', () => {
    expect(
      parseCoinCheckoutCallback(
        'rokn://payment-result?status=success&order_ref=PKG-12345678&coins=600',
      ),
    ).toEqual({
      valid: true,
      status: 'success',
      orderRef: 'PKG-12345678',
      coins: 600,
    });
  });

  it('rejects legacy aliases and duplicate callback fields', () => {
    expect(
      parseCoinCheckoutCallback(
        'rokn://payment-result?status=success&orderRef=PKG-12345678&coins=600',
      ),
    ).toEqual({valid: false, coins: 0});
    expect(
      parseCoinCheckoutCallback(
        'rokn://payment-result?status=success&status=failed&order_ref=PKG-12345678&coins=0',
      ),
    ).toEqual({valid: false, coins: 0});
  });

  it('rejects incomplete or malformed provider results', () => {
    expect(
      parseCoinCheckoutCallback(
        'rokn://payment-result?status=approved&order_ref=PKG-12345678&coins=600',
      ),
    ).toEqual({valid: false, coins: 0});
    expect(
      parseCoinCheckoutCallback(
        'rokn://payment-result?status=success&order_ref=PKG-12345678&coins=1.5',
      ),
    ).toEqual({valid: false, coins: 0});
  });
});
