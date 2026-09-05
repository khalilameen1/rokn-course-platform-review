import {coinCheckoutFailureDisposition} from '../src/services/coinCheckoutTypes';

describe('coin checkout failure disposition', () => {
  it.each([
    'payment_configuration_unavailable',
    'checkout_temporarily_unavailable',
    'recovery_in_progress',
    'feature_temporarily_unavailable',
    'FEATURE_CHECKOUT_DISABLED',
    'CHECKOUT_DISABLED_FOR_DISTRIBUTION',
  ])('does not present %s as an uncertain completed payment', code => {
    expect(coinCheckoutFailureDisposition(code)).toBe('opening_unavailable');
  });

  it('keeps catalogue changes and genuinely unknown outcomes distinct', () => {
    expect(coinCheckoutFailureDisposition('package_terms_changed')).toBe(
      'catalogue_changed',
    );
    expect(coinCheckoutFailureDisposition('payment_status_timeout')).toBe(
      'status_uncertain',
    );
  });
});
