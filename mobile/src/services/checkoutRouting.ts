import {Platform} from 'react-native';
import {
  DISTRIBUTION_CHANNEL,
  type DistributionChannel,
} from '../constants/distribution';
import type {CoinPackage} from './api/coinPackageMapper';

export type CheckoutTransport =
  | {
      kind: 'native';
      channel: 'play' | 'appstore';
      apiChannel: 'google' | 'apple';
    }
  | {
      kind: 'external';
      channel: 'direct';
      apiChannel: 'direct';
      surface: 'browser' | 'in_app';
    };

/** The only policy that selects a payment provider or presentation surface.
 * The transport selected at consent is carried through retries unchanged. */
export function resolveCheckoutTransport(
  purpose: 'course' | 'wallet',
  platform: string,
  distribution: DistributionChannel,
): CheckoutTransport {
  if (!['direct', 'play', 'appstore'].includes(distribution)) {
    throw new Error('CHECKOUT_DISTRIBUTION_INVALID');
  }
  if (purpose === 'course' && platform === 'android') {
    return {
      kind: 'external',
      channel: 'direct',
      apiChannel: 'direct',
      surface: 'browser',
    };
  }
  if (distribution === 'direct') {
    return {
      kind: 'external',
      channel: 'direct',
      apiChannel: 'direct',
      surface: 'in_app',
    };
  }
  if (distribution === 'play')
    return {kind: 'native', channel: 'play', apiChannel: 'google'};
  if (distribution === 'appstore')
    return {kind: 'native', channel: 'appstore', apiChannel: 'apple'};
  throw new Error('CHECKOUT_DISTRIBUTION_INVALID');
}

export const courseCheckoutTransport = resolveCheckoutTransport(
  'course',
  Platform.OS,
  DISTRIBUTION_CHANNEL,
);
export const walletCheckoutTransport = resolveCheckoutTransport(
  'wallet',
  Platform.OS,
  DISTRIBUTION_CHANNEL,
);
export const canRecoverExternalCheckout = [
  courseCheckoutTransport,
  walletCheckoutTransport,
].some(transport => transport.kind === 'external');

export const checkoutProviderIdentity = (
  transport: CheckoutTransport,
  item: CoinPackage,
) =>
  transport.kind === 'external'
    ? ['kashier', 'EGP', transport.surface]
    : transport.channel === 'play'
    ? ['google_play', 'store', item.storeProductIds?.google]
    : ['apple_app_store', 'store', item.storeProductIds?.apple];

export function checkoutPackageChannel(value: unknown): DistributionChannel {
  if (value === 'direct') return 'direct';
  if (value === 'google') return 'play';
  if (value === 'apple') return 'appstore';
  throw new Error('API_CONTRACT_INVALID_COURSE_CHECKOUT');
}
