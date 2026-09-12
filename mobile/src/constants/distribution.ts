/**
 * Distribution-aware commerce guard.
 *
 * Store builds use native billing. Direct builds use Rokn's web checkout.
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
 * Play may redeem access already issued by an educational organization;
 * paid coin packages still use Google Play Billing.
 * App Store builds do not expose a generic code/key unlock because App Review
 * Guideline 3.1.1 requires digital-content unlocks to use in-app purchase.
 */
export const getDistributionCapabilities = (
  channel: DistributionChannel,
): DistributionCapabilities => ({
  canRedeemCourseAccessCode: channel !== 'appstore',
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
