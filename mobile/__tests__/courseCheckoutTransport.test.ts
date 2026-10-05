jest.mock('../src/constants/apiBaseUrl', () => ({
  roknApiUrl: 'https://rokn.app/api/v1/',
}));
jest.mock('../src/constants/distribution', () => ({
  DISTRIBUTION_CHANNEL: 'play',
}));

describe('course checkout transport', () => {
  const payment = `https://rokn.app/course-payment/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa?order=12&expires=1900000000&signature=${'a'.repeat(
    64,
  )}`;

  it('uses a single routing policy without changing wallet distribution', () => {
    jest.isolateModules(() => {
      jest.doMock('react-native', () => ({Platform: {OS: 'android'}}));
      const transport = require('../src/services/checkoutRouting');
      expect(transport.courseCheckoutTransport).toMatchObject({
        kind: 'external',
        apiChannel: 'direct',
        surface: 'browser',
      });
      expect(transport.walletCheckoutTransport).toMatchObject({
        kind: 'native',
        apiChannel: 'google',
      });
      expect(
        transport.resolveCheckoutTransport('course', 'ios', 'appstore'),
      ).toMatchObject({kind: 'native', apiChannel: 'apple'});
      expect(
        transport.resolveCheckoutTransport('wallet', 'android', 'direct'),
      ).toMatchObject({kind: 'external', surface: 'in_app'});
      expect(() =>
        transport.resolveCheckoutTransport('course', 'android', 'unknown'),
      ).toThrow('CHECKOUT_DISTRIBUTION_INVALID');
      expect(
        require('../src/constants/distribution').DISTRIBUTION_CHANNEL,
      ).toBe('play');
    });
  });

  it('keeps non-Android checkout on its configured channel', () => {
    jest.isolateModules(() => {
      jest.doMock('react-native', () => ({Platform: {OS: 'ios'}}));
      const transport = require('../src/services/checkoutRouting');
      expect(transport.courseCheckoutTransport).toMatchObject({
        kind: 'native',
        channel: 'play',
      });
    });
  });

  it('accepts only a signed course URL on the configured Rokn origin', () => {
    const {isCoursePaymentUrl} = require('../src/services/coursePaymentUrl');
    expect(isCoursePaymentUrl(payment)).toBe(true);
    for (const invalid of [
      payment.replace('rokn.app', 'rokn.app.evil.example'),
      payment.replace('https:', 'http:'),
      payment.replace('course-payment/', 'recharge/'),
      payment.replace('expires=1900000000&', ''),
      payment.replace('signature=', 'token='),
      payment.replace('rokn.app/', 'learner:password@rokn.app/'),
      `${payment}#fragment`,
      'https://checkout.kashier.io/',
    ])
      expect(isCoursePaymentUrl(invalid)).toBe(false);
  });
});
