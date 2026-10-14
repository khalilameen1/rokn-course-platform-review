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
      ).toMatchObject({
        kind: 'native',
        channel: 'appstore',
        apiChannel: 'apple',
      });
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

  it('keeps non-App-Store transport compatibility on iOS', () => {
    jest.isolateModules(() => {
      jest.doMock('react-native', () => ({Platform: {OS: 'ios'}}));
      const transport = require('../src/services/checkoutRouting');
      expect(transport.courseCheckoutTransport).toMatchObject({
        kind: 'external',
        channel: 'direct',
        apiChannel: 'direct',
        surface: 'browser',
      });
      expect(transport.walletCheckoutTransport).toMatchObject({
        kind: 'native',
        channel: 'play',
      });
    });
  });

  it.each(['android', 'ios'] as const)(
    'selects the configured course provider on %s without changing wallet recovery',
    platform => {
      const {
        resolveCheckoutTransport,
      } = require('../src/services/checkoutRouting');
      for (const channel of ['direct', 'play', 'appstore'] as const) {
        expect(resolveCheckoutTransport('course', platform, channel)).toEqual(
          platform === 'ios' && channel === 'appstore'
            ? {kind: 'native', channel: 'appstore', apiChannel: 'apple'}
            : {
                kind: 'external',
                channel: 'direct',
                apiChannel: 'direct',
                surface: 'browser',
              },
        );
      }
      expect(resolveCheckoutTransport('wallet', platform, 'appstore')).toEqual({
        kind: 'native',
        channel: 'appstore',
        apiChannel: 'apple',
      });
    },
  );

  it('binds the real App Store configuration to native Apple purchase and recovery', () => {
    jest.resetModules();
    jest.isolateModules(() => {
      jest.doMock('react-native', () => ({Platform: {OS: 'ios'}}));
      jest.doMock('../src/constants/distribution', () => ({
        DISTRIBUTION_CHANNEL: 'appstore',
      }));
      expect(require('react-native').Platform.OS).toBe('ios');
      expect(
        require('../src/constants/distribution').DISTRIBUTION_CHANNEL,
      ).toBe('appstore');
      const transport = require('../src/services/checkoutRouting');
      expect(transport.courseCheckoutTransport).toEqual({
        kind: 'native',
        channel: 'appstore',
        apiChannel: 'apple',
      });
      expect(transport.walletCheckoutTransport).toEqual(
        transport.courseCheckoutTransport,
      );
      expect(transport.canRecoverExternalCheckout).toBe(false);
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
