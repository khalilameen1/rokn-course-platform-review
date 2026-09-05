const mockSetTag = jest.fn();
const mockWithScope = jest.fn((callback: (scope: unknown) => void) =>
  callback({setLevel: jest.fn(), setTag: mockSetTag}),
);

jest.mock('@sentry/react-native', () => ({
  captureException: jest.fn(),
  init: jest.fn(),
  reactNativeErrorHandlersIntegration: jest.fn(() => ({name: 'errors'})),
  setUser: jest.fn(),
  withScope: (callback: (scope: unknown) => void) => mockWithScope(callback),
}));

describe('coin checkout telemetry correlation', () => {
  const orderRef = 'PKG-ORDER-12345678';
  const responseRequestId = '57f342cf-0e56-4fe8-a4db-80a03630f333';
  const configRequestId = 'c86b104e-e6cd-44e9-98cc-cf9d4c26f3f4';
  const previousDsn = process.env.EXPO_PUBLIC_SENTRY_DSN;

  afterAll(() => {
    process.env.EXPO_PUBLIC_SENTRY_DSN = previousDsn;
  });

  it('keeps the HTTP request id and payment order reference as separate tags', () => {
    process.env.EXPO_PUBLIC_SENTRY_DSN = 'https://public@example.com/1';
    jest.isolateModules(() => {
      const telemetry = require('../src/services/sentryTelemetry') as typeof import('../src/services/sentryTelemetry');
      const error = Object.assign(new Error('payment_failed'), {
        config: {
          url: 'payment/reconcile/PKG-ORDER-12345678',
          headers: {'x-request-id': configRequestId},
        },
        response: {headers: {'X-Request-ID': responseRequestId}},
      });

      expect(
        telemetry.requestCorrelationFor(error, {
          endpoint: 'payment/reconcile',
          orderRef,
        }),
      ).toEqual({
        endpoint: '/payment/reconcile',
        requestId: responseRequestId,
        orderRef,
      });

      telemetry.initializeSentry();
      telemetry.captureSentryDiagnostic(error, {
        clientEventId: '8d78f65e-8385-4b8b-8ea1-ccf985a4a191',
        eventName: 'payment_flow_failure',
        errorCode: 'PAYMENT_FAILED',
        source: 'coin_checkout',
        fatal: false,
        endpoint: 'payment/reconcile',
        orderRef,
      });
    });

    expect(mockSetTag).toHaveBeenCalledWith('request_id', responseRequestId);
    expect(mockSetTag).toHaveBeenCalledWith('order_ref', orderRef);
  });

  it('does not invent a request id for an aggregate checkout timeout', () => {
    jest.isolateModules(() => {
      const {requestCorrelationFor} = require('../src/services/sentryTelemetry') as typeof import('../src/services/sentryTelemetry');

      expect(
        requestCorrelationFor(new Error('payment_status_timeout'), {
          endpoint: 'payment/reconcile',
          orderRef,
        }),
      ).toEqual({
        endpoint: '/payment/reconcile',
        requestId: undefined,
        orderRef,
      });
      expect(
        requestCorrelationFor(new Error('payment_status_timeout'), {
          orderRef: 'https://example.com/payment?token=secret',
        }).orderRef,
      ).toBeUndefined();
    });
  });
});
