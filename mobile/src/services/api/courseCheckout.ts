import {publicRequest} from '../../constants/api';
import {
  captureAccountSessionBoundary,
  assertAccountSessionBoundary,
} from '../../constants/helpers';
import {DISTRIBUTION_CHANNEL} from '../../constants/distribution';
import {
  canonicalAccessPlanCode,
  numericCourseId,
  mapFinancialPackages,
} from './courseAccessValidation';
import {isApiRecord, payload} from './common';
import type {CoinPackage} from './coinPackageMapper';

export type CourseCheckoutMode = 'purchase' | 'upgrade';
export type CourseCheckoutStatus =
  | 'quoted'
  | 'pending_payment'
  | 'completed'
  | 'cancelled'
  | 'expired'
  | 'reconfirm_required';
export type CourseCheckout = {
  id: string;
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
    deficit !== Math.max(0, paidCoins - paidBalance)
  ) {
    throw new Error('API_CONTRACT_INVALID_COURSE_CHECKOUT');
  }
  const packages = mapFinancialPackages(value.recommended_packages);
  const selectedPackageId = isApiRecord(value.selected_package)
    ? String(value.selected_package.id)
    : undefined;
  return {
    id,
    status,
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
  couponCode?: string;
  packageId?: string;
}) =>
  scoped(() =>
    publicRequest.post('course-checkouts', {
      course_id: numericCourseId(input.courseId),
      access_plan_code: canonicalAccessPlanCode(input.planCode),
      mode: input.mode,
      channel:
        DISTRIBUTION_CHANNEL === 'play'
          ? 'google'
          : DISTRIBUTION_CHANNEL === 'appstore'
          ? 'apple'
          : 'direct',
      ...(input.couponCode ? {coupon_code: input.couponCode.trim()} : {}),
      ...(input.packageId ? {package_id: Number(input.packageId)} : {}),
    }),
  );

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
