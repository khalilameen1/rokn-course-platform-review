import {publicRequest} from '../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import {normalizeHumanIdentifier} from '../../utils/unicodeText';
import type {CoinPackage} from './coinPackageMapper';
import {
  clearCoursePurchaseAttemptKey,
  getOrCreateCoursePurchaseAttemptKey,
} from './courseAccessAttemptStore';
import {
  canonicalAccessPlanCode,
  mapFinancialPackages,
  numericCourseId,
} from './courseAccessValidation';
import {
  type ApiRecord,
  isApiRecord,
  payload,
  requireNonNegativeNumber,
} from './common';

type CourseAuthorizationDto = {
  course_revision?: unknown;
  access_plan_code?: unknown;
  total_balance?: unknown;
  spendable_balance?: unknown;
  deficit?: unknown;
  recommended_packages?: unknown;
  purchased_balance?: unknown;
  reward_balance?: unknown;
  original_price?: unknown;
  discount_amount?: unknown;
  final_price?: unknown;
  coupon?: {
    code?: unknown;
    discount_percentage?: unknown;
  } | null;
};

const errorBody = (error: unknown): ApiRecord => {
  if (!isApiRecord(error)) return {};
  if (isApiRecord(error.data)) return error.data;
  if (isApiRecord(error.response) && isApiRecord(error.response.data)) {
    return error.response.data;
  }
  return {};
};

const mapBalanceBreakdown = (data: CourseAuthorizationDto) => {
  const balance = requireNonNegativeNumber(
    data.total_balance,
    'COURSE_PURCHASE_TOTAL_BALANCE',
  );
  const spendableBalance = requireNonNegativeNumber(
    data.spendable_balance,
    'COURSE_PURCHASE_SPENDABLE_BALANCE',
  );
  const paidBalance = requireNonNegativeNumber(
    data.purchased_balance,
    'COURSE_PURCHASE_PAID_BALANCE',
  );
  const rewardBalance = requireNonNegativeNumber(
    data.reward_balance,
    'COURSE_PURCHASE_REWARD_BALANCE',
  );
  if (paidBalance + rewardBalance !== balance || spendableBalance > balance) {
    throw new Error('API_CONTRACT_INVALID_COURSE_PURCHASE_BALANCE');
  }
  return {balance, spendableBalance, paidBalance, rewardBalance};
};

export type CoursePurchaseResult =
  | {
      kind: 'success';
      balance: number;
      spendableBalance: number;
      paidBalance: number;
      rewardBalance: number;
      originalPrice: number;
      discountAmount: number;
    }
  | {
      kind: 'insufficient';
      balance: number;
      spendableBalance: number;
      paidBalance: number;
      rewardBalance: number;
      deficit: number;
      packages: CoinPackage[];
    };

export type CoursePurchaseQuote = {
  courseRevision: number;
  accessPlanCode: string;
  originalPrice: number;
  discountAmount: number;
  finalPrice: number;
  couponCode: string;
  discountPercentage: number;
};

export const quoteCoursePurchase = async (
  courseId: string,
  accessPlanCode: string,
  couponCode: string,
  expectedCourseRevision?: number,
): Promise<CoursePurchaseQuote> => {
  const normalizedCoupon = normalizeHumanIdentifier(couponCode);
  const courseIdValue = numericCourseId(courseId);
  const normalizedPlanCode = canonicalAccessPlanCode(accessPlanCode);
  const data = payload<CourseAuthorizationDto>(
    await publicRequest.post('courses/purchase-quote', {
      course_id: courseIdValue,
      access_plan_code: normalizedPlanCode,
      coupon_code: normalizedCoupon,
      ...(Number.isSafeInteger(expectedCourseRevision) &&
      Number(expectedCourseRevision) > 0
        ? {expected_course_revision: expectedCourseRevision}
        : {}),
    }),
  );
  const originalPrice = requireNonNegativeNumber(
    data.original_price,
    'COURSE_QUOTE_ORIGINAL_PRICE',
  );
  const discountAmount = requireNonNegativeNumber(
    data.discount_amount,
    'COURSE_QUOTE_DISCOUNT_AMOUNT',
  );
  const finalPrice = requireNonNegativeNumber(
    data.final_price,
    'COURSE_QUOTE_FINAL_PRICE',
  );
  const discountPercentage = requireNonNegativeNumber(
    data.coupon?.discount_percentage ?? 0,
    'COURSE_QUOTE_DISCOUNT_PERCENTAGE',
  );
  const courseRevision = Number(data.course_revision);
  const returnedPlanCode = String(data.access_plan_code || '')
    .trim()
    .toLowerCase();
  if (
    discountAmount > originalPrice ||
    finalPrice + discountAmount !== originalPrice ||
    discountPercentage > 100 ||
    !Number.isSafeInteger(courseRevision) ||
    courseRevision < 1 ||
    returnedPlanCode !== normalizedPlanCode
  ) {
    throw new Error('API_CONTRACT_INVALID_COURSE_QUOTE');
  }
  return {
    courseRevision,
    accessPlanCode: returnedPlanCode,
    originalPrice,
    discountAmount,
    finalPrice,
    couponCode: normalizeHumanIdentifier(data.coupon?.code || normalizedCoupon),
    discountPercentage,
  };
};

export const purchaseCourse = async (
  courseId: string,
  accessPlanCode: string,
  couponCode?: string,
  expectedPrice?: number,
  expectedCourseRevision?: number,
): Promise<CoursePurchaseResult> => {
  const boundary = await captureAccountSessionBoundary();
  const courseIdValue = numericCourseId(courseId);
  const normalizedPlanCode = canonicalAccessPlanCode(accessPlanCode);
  const normalizedCouponCode = normalizeHumanIdentifier(couponCode);
  const purchaseIntent = {
    courseId: courseIdValue,
    accessPlanCode: normalizedPlanCode,
    couponCode: normalizedCouponCode,
  };
  const idempotencyKey = await getOrCreateCoursePurchaseAttemptKey(
    purchaseIntent,
    boundary,
  );
  try {
    assertAccountSessionBoundary(boundary);
    const data = payload<CourseAuthorizationDto>(
      await publicRequest.post('courses/authorize', {
        course_id: courseIdValue,
        access_plan_code: normalizedPlanCode,
        ...(normalizedCouponCode ? {coupon_code: normalizedCouponCode} : {}),
        ...(Number.isFinite(expectedPrice)
          ? {expected_price: Math.max(0, Math.trunc(expectedPrice as number))}
          : {}),
        ...(Number.isSafeInteger(expectedCourseRevision) &&
        Number(expectedCourseRevision) > 0
          ? {expected_course_revision: expectedCourseRevision}
          : {}),
        idempotency_key: idempotencyKey,
      }),
    );
    const balances = mapBalanceBreakdown(data);
    const originalPrice = requireNonNegativeNumber(
      data.original_price,
      'COURSE_PURCHASE_ORIGINAL_PRICE',
    );
    const discountAmount = requireNonNegativeNumber(
      data.discount_amount,
      'COURSE_PURCHASE_DISCOUNT_AMOUNT',
    );
    if (discountAmount > originalPrice) {
      throw new Error('API_CONTRACT_INVALID_COURSE_PURCHASE_PRICE');
    }
    await clearCoursePurchaseAttemptKey(
      purchaseIntent,
      idempotencyKey,
      boundary,
    );
    return {
      kind: 'success',
      ...balances,
      originalPrice,
      discountAmount,
    };
  } catch (error: unknown) {
    const body = errorBody(error);
    const data = isApiRecord(body.data)
      ? (body.data as CourseAuthorizationDto)
      : {};
    if (body.code === 'insufficient_coins' || data.deficit !== undefined) {
      assertAccountSessionBoundary(boundary);
      const balances = mapBalanceBreakdown(data);
      const deficit = requireNonNegativeNumber(
        data.deficit,
        'COURSE_PURCHASE_DEFICIT',
      );
      if (deficit <= 0) {
        throw new Error('API_CONTRACT_INVALID_COURSE_PURCHASE_DEFICIT');
      }
      return {
        kind: 'insufficient',
        ...balances,
        deficit,
        packages: mapFinancialPackages(data.recommended_packages),
      };
    }
    throw error;
  }
};
