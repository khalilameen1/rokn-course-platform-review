/**
 * Distribution-aware commerce guard.
 *
 * Wallet billing remains distribution-aware. Mobile course subscriptions use
 * their separate transport selected by checkoutRouting.
 */
export type DistributionChannel = 'direct' | 'play' | 'appstore';

export type DistributionCapabilities = {
  canRedeemCourseAccessCode: boolean;
  canStartExternalCheckout: boolean;
  canStartNativeCheckout: boolean;
};

/**
 * Keep access redemption independent from checkout.
 *
 * Course grant redemption is shared by both mobile platforms. Wallet/native
 * receipt compatibility remains distribution-aware. These capabilities describe
 * the implemented product, not proof of approval for an external-payment or
 * grant-unlock flow in any store region; see the release parity notes.
 */
export const getDistributionCapabilities = (
  channel: DistributionChannel,
): DistributionCapabilities => ({
  canRedeemCourseAccessCode: ['direct', 'play', 'appstore'].includes(channel),
  canStartExternalCheckout: channel === 'direct',
  canStartNativeCheckout: channel === 'play' || channel === 'appstore',
});

const configuredChannel = process.env.EXPO_PUBLIC_DISTRIBUTION_CHANNEL;

export const DISTRIBUTION_CHANNEL: DistributionChannel =
  configuredChannel === 'direct' || configuredChannel === 'appstore'
    ? configuredChannel
    : 'play';

export const IS_PLAY_DISTRIBUTION = DISTRIBUTION_CHANNEL === 'play';
export const IS_APP_STORE_DISTRIBUTION = DISTRIBUTION_CHANNEL === 'appstore';
export const IS_STORE_DISTRIBUTION =
  IS_PLAY_DISTRIBUTION || IS_APP_STORE_DISTRIBUTION;

const distributionCapabilities =
  getDistributionCapabilities(DISTRIBUTION_CHANNEL);

// External checkout is a capability of the explicitly distributed direct APK
// only. Store builds use native billing and never infer permission for external
// checkout from an unknown/missing channel value.
export const CAN_START_EXTERNAL_CHECKOUT =
  distributionCapabilities.canStartExternalCheckout;
export const CAN_START_NATIVE_CHECKOUT =
  distributionCapabilities.canStartNativeCheckout;
export const CAN_START_COIN_CHECKOUT =
  CAN_START_EXTERNAL_CHECKOUT || CAN_START_NATIVE_CHECKOUT;

export const CAN_REDEEM_COURSE_ACCESS_CODE =
  distributionCapabilities.canRedeemCourseAccessCode;
