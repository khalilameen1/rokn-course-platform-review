import {publicRequest} from '../../constants/api';
import {
  captureAccountSessionBoundary,
  assertAccountSessionBoundary,
} from '../../constants/helpers';
import {
  courseCheckoutTransport,
  checkoutPackageChannel,
} from '../checkoutRouting';
import {
  canonicalAccessPlanCode,
  numericCourseId,
} from './courseAccessValidation';
import {isApiRecord, payload} from './common';
import type {CoinPackage} from './coinPackageMapper';
import {mapCoinPackages} from './coinPackageMapper';

export type CourseCheckoutMode = 'purchase' | 'upgrade';
export type CourseCheckoutFeature = 'chat' | 'project_discussion';
export type CourseCheckoutStatus =
  | 'quoted'
  | 'pending_payment'
  | 'completed'
  | 'cancelled'
  | 'expired'
  | 'reconfirm_required';
export type CourseCheckout = {
  id: string;
  channel: 'direct' | 'google' | 'apple';
  fundingMode?: 'package' | 'exact_shortfall';
  status: CourseCheckoutStatus;
  courseId: string;
  planCode: string;
  courseRevision: number;
  originalPrice: number;
  discountAmount: number;
  finalPrice: number;
  paidCoins: number;
  rewardCoins: number;
  paidBalance: number;
  rewardBalance: number;
  deficit: number;
  remainingPaidCoins: number;
  remainingRewardCoins: number;
  expiresAt: string;
  packages: CoinPackage[];
  selectedPackageId?: string;
  selectedPackage?: CoinPackage;
  canResumePayment?: boolean;
  paymentExpiresAt?: string;
};

const integer = (value: unknown) => {
  if (
    value === null ||
    value === undefined ||
    value === '' ||
    !Number.isSafeInteger(Number(value)) ||
    Number(value) < 0
  ) {
    throw new Error('API_CONTRACT_INVALID_COURSE_CHECKOUT');
  }
  return Number(value);
};

export function mapCourseCheckout(value: unknown): CourseCheckout {
  if (!isApiRecord(value) || !isApiRecord(value.allocation))
    throw new Error('API_CONTRACT_INVALID_COURSE_CHECKOUT');
  const id = String(value.id || '');
  const status = String(value.status || '') as CourseCheckoutStatus;
  const expiresAt = String(value.expires_at || '');
  const originalPrice = integer(value.original_price);
  const discountAmount = integer(value.discount_amount);
  const finalPrice = integer(value.final_price);
  const paidCoins = integer(value.allocation.paid_coins);
  const rewardCoins = integer(value.allocation.reward_coins);
  const paidBalance = integer(value.purchased_balance);
  const rewardBalance = integer(value.reward_balance);
  const deficit = integer(value.deficit);
  const fundingMode = value.funding_mode ?? 'package';
  if (
    !/^[a-zA-Z0-9-]{1,100}$/.test(id) ||
    ![
      'quoted',
      'pending_payment',
      'completed',
      'cancelled',
      'expired',
      'reconfirm_required',
    ].includes(status) ||
    !Number.isFinite(Date.parse(expiresAt)) ||
    originalPrice !== finalPrice + discountAmount ||
    paidCoins + rewardCoins !== finalPrice ||
    rewardCoins > rewardBalance ||
    integer(value.course_revision) < 1 ||
    deficit !== Math.max(0, paidCoins - paidBalance) ||
    !['package', 'exact_shortfall'].includes(String(fundingMode)) ||
    (fundingMode === 'exact_shortfall' && value.channel !== 'direct')
  ) {
    throw new Error('API_CONTRACT_INVALID_COURSE_CHECKOUT');
  }
  const packages = mapCoinPackages(
    value.recommended_packages,
    'API_CONTRACT_INVALID_RECOMMENDED_PACKAGES',
    checkoutPackageChannel(value.channel),
  );
  const selectedPackageId = isApiRecord(value.selected_package)
    ? String(value.selected_package.id)
    : undefined;
  const selectedPackage = selectedPackageId
    ? mapCoinPackages(
        [value.selected_package],
        'API_CONTRACT_INVALID_SELECTED_PACKAGE',
        checkoutPackageChannel(value.channel),
      )[0]
    : undefined;
  if (
    selectedPackageId &&
    (!selectedPackage ||
      selectedPackage.coins < deficit ||
      (fundingMode === 'exact_shortfall' && selectedPackage.coins !== deficit))
  ) {
    throw new Error('API_CONTRACT_INVALID_SELECTED_PACKAGE');
  }
  return {
    id,
    channel: value.channel as CourseCheckout['channel'],
    status,
    fundingMode: fundingMode as CourseCheckout['fundingMode'],
    expiresAt,
    originalPrice,
    discountAmount,
    finalPrice,
    paidCoins,
    rewardCoins,
    paidBalance,
    rewardBalance,
    deficit,
    courseId: String(numericCourseId(String(value.course_id))),
    planCode: canonicalAccessPlanCode(String(value.access_plan_code)),
    courseRevision: integer(value.course_revision),
    remainingPaidCoins: integer(value.remaining_purchased_balance),
    remainingRewardCoins: integer(value.remaining_reward_balance),
    packages,
    selectedPackageId,
    selectedPackage,
    canResumePayment:
      isApiRecord(value.payment) && value.payment.can_resume === true,
    paymentExpiresAt:
      isApiRecord(value.payment) && typeof value.payment.expires_at === 'string'
        ? value.payment.expires_at
        : undefined,
  };
}

const scoped = async (request: () => Promise<unknown>) => {
  const boundary = await captureAccountSessionBoundary();
  const response = await request();
  assertAccountSessionBoundary(boundary);
  return mapCourseCheckout(payload(response));
};

export const quoteCourseCheckout = (input: {
  courseId: string;
  planCode: string;
  mode: CourseCheckoutMode;
  requiredFeature?: CourseCheckoutFeature;
  couponCode?: string;
  packageId?: string;
}) =>
  scoped(() =>
    publicRequest.post('course-checkouts', {
      course_id: numericCourseId(input.courseId),
      access_plan_code: canonicalAccessPlanCode(input.planCode),
      mode: input.mode,
      ...(input.requiredFeature
        ? {required_feature: input.requiredFeature}
        : {}),
      channel: courseCheckoutTransport.apiChannel,
      ...(courseCheckoutTransport.apiChannel === 'direct'
        ? {funding_mode: 'exact_shortfall'}
        : {}),
      ...(input.couponCode ? {coupon_code: input.couponCode.trim()} : {}),
      ...(input.packageId ? {package_id: Number(input.packageId)} : {}),
    }),
  ).then(quote => {
    // A server that does not support this contract must not silently charge a
    // full package while the latest app promises the exact missing amount.
    if (
      courseCheckoutTransport.apiChannel === 'direct' &&
      quote.fundingMode !== 'exact_shortfall'
    ) {
      throw new Error('API_UNSUPPORTED_EXACT_COURSE_FUNDING');
    }
    return quote;
  });

export const getCourseCheckout = (id: string) =>
  scoped(() => publicRequest.get(`course-checkouts/${encodeURIComponent(id)}`));
export const resumeCourseCheckout = (id: string) =>
  scoped(() =>
    publicRequest.post(`course-checkouts/${encodeURIComponent(id)}/resume`),
  );
export const getLatestCourseCheckout = async (
  courseId: string,
): Promise<CourseCheckout | null> => {
  const boundary = await captureAccountSessionBoundary();
  const response = await publicRequest.get('course-checkouts', {
    params: {course_id: numericCourseId(courseId)},
  });
  assertAccountSessionBoundary(boundary);
  const value = payload(response);
  return value === null ? null : mapCourseCheckout(value);
};
export const authorizeCourseCheckout = (id: string) =>
  scoped(() =>
    publicRequest.post(`course-checkouts/${encodeURIComponent(id)}/authorize`),
  );
export const cancelCourseCheckout = (id: string) =>
  scoped(() =>
    publicRequest.post(`course-checkouts/${encodeURIComponent(id)}/cancel`),
  );

/** Localized store prices are the only cash prices a store build can compare. */
export function selectCheckoutPackage(
  packages: CoinPackage[],
  deficit: number,
): CoinPackage | undefined {
  if (deficit <= 0) return undefined;
  return packages
    .filter(
      item =>
        item.coins >= deficit && Number.isFinite(item.price) && item.price > 0,
    )
    .sort(
      (a, b) =>
        a.price - b.price || a.coins - b.coins || Number(a.id) - Number(b.id),
    )[0];
}
