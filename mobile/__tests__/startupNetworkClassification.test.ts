import {
  friendlyNetworkMessage,
  networkFailureKind,
  retryableReadTransportFailure,
  transientReadFailureAllowsCache,
} from '../src/services/networkExperience';
import fs from 'fs';
import path from 'path';

describe('startup network failure classification', () => {
  it('distinguishes an origin wake-up from an incompatible API payload', () => {
    const maintenance = {status: 503};
    const contract = new Error('COURSE_CATALOGUE_CONTRACT_INVALID');

    expect(networkFailureKind(maintenance)).toBe('maintenance');
    expect(retryableReadTransportFailure(maintenance)).toBe(true);
    expect(transientReadFailureAllowsCache(maintenance)).toBe(true);
    expect(networkFailureKind(contract)).toBe('contract');
    expect(transientReadFailureAllowsCache(contract)).toBe(false);
    expect(friendlyNetworkMessage(contract, 'الكورسات')).toContain(
      'تعذّر قراءة الكورسات',
    );
  });

  it('normalizes nested transport errors and silent account-boundary cancellation', () => {
    expect(
      networkFailureKind({
        response: {status: 503, data: {code: 'origin_waking'}},
      }),
    ).toBe('maintenance');
    expect(networkFailureKind({status: 408})).toBe('timeout');
    expect(networkFailureKind({code: 'ENETUNREACH'})).toBe('offline');
    expect(
      networkFailureKind({
        response: {
          status: 422,
          data: {message: 'timeout must be a positive integer'},
        },
      }),
    ).toBe('validation');
    expect(
      networkFailureKind(new Error('ACCOUNT_CHANGED_DURING_REQUEST')),
    ).toBe('cancelled');
    expect(
      friendlyNetworkMessage(
        new Error('ACCOUNT_CHANGED_DURING_REQUEST'),
        'الكورسات',
      ),
    ).toBe('');
  });

  it('never conceals an invalid endpoint contract behind a server cache fallback', () => {
    const invalidContract = {
      response: {
        status: 500,
        data: {code: 'COURSE_CATALOGUE_CONTRACT_INVALID'},
      },
    };

    expect(networkFailureKind(invalidContract)).toBe('contract');
    expect(transientReadFailureAllowsCache(invalidContract)).toBe(false);
    expect(retryableReadTransportFailure(invalidContract)).toBe(false);
    expect(retryableReadTransportFailure({status: 500})).toBe(false);
    expect(retryableReadTransportFailure({status: 502})).toBe(true);
    expect(retryableReadTransportFailure({status: 429})).toBe(false);
    expect(transientReadFailureAllowsCache({status: 429})).toBe(true);
  });

  it('never invents provider actions when discovery has no valid contract', () => {
    const shell = fs.readFileSync(
      path.resolve(__dirname, '../src/components/auth/SocialAuthShell.tsx'),
      'utf8',
    );
    const view = fs.readFileSync(
      path.resolve(__dirname, '../src/components/auth/SocialAuthView.tsx'),
      'utf8',
    );

    expect(shell).toContain("phase: 'discovery_failed'");
    expect(shell).toContain('failureCode: socialAuthFailureCode(error)');
    expect(shell).toContain('authMethods?.providers.includes(provider)');
    expect(view).toContain('providerDefinitions.find');
    expect(view).toContain('حاول مرة أخرى');
  });
});
