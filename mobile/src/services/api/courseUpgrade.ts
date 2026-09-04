import {publicRequest} from '../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import type {CoinPackage} from './coinPackageMapper';
import {
  clearCourseUpgradeAttemptKey,
  getOrCreateCourseUpgradeAttemptKey,
} from './courseAccessAttemptStore';
import {mapFinancialPackages, numericCourseId} from './courseAccessValidation';
import {firstBoolean, payload, requireNonNegativeNumber} from './common';

type CourseUpgradeQuoteDto = {
  course_revision?: unknown;
  already_upgraded?: unknown;
  chat_available?: unknown;
  certificate_available?: unknown;
  ai_included?: unknown;
  course_id?: unknown;
  course_title?: unknown;
  upgrade_price?: unknown;
  total_balance?: unknown;
  spendable_balance?: unknown;
  deficit?: unknown;
  reward_contribution_cap_per_course?: unknown;
  recommended_packages?: unknown;
  target_plan_code?: unknown;
  target_plan_name?: unknown;
  target_message_limit?: unknown;
};

export type CourseChatUpgradeQuote = {
  courseRevision: number;
  alreadyUpgraded: boolean;
  chatAvailable: boolean;
  certificateAvailable: boolean;
  aiIncluded: boolean;
  courseId?: string;
  courseTitle?: string;
  price: number;
  totalBalance: number;
  spendableBalance: number;
  deficit: number;
  rewardContributionCap: number;
  packages: CoinPackage[];
  targetPlanCode?: string;
  targetPlanName?: string;
  targetMessageLimit?: number;
};

const mapCourseChatUpgradeQuote = (
  data: CourseUpgradeQuoteDto,
): CourseChatUpgradeQuote => {
  const courseRevision = Number(data.course_revision);
  if (!Number.isSafeInteger(courseRevision) || courseRevision < 1) {
    throw new Error('API_CONTRACT_INVALID_COURSE_UPGRADE_REVISION');
  }
  const alreadyUpgraded = firstBoolean(data.already_upgraded) ?? false;
  if (alreadyUpgraded) {
    return {
      courseRevision,
      alreadyUpgraded: true,
      chatAvailable: firstBoolean(data.chat_available) ?? true,
      certificateAvailable: firstBoolean(data.certificate_available) ?? false,
      aiIncluded: firstBoolean(data.ai_included) ?? true,
      courseId: data.course_id ? String(data.course_id) : undefined,
      courseTitle: data.course_title ? String(data.course_title) : undefined,
      price: 0,
      totalBalance: 0,
      spendableBalance: 0,
      deficit: 0,
      rewardContributionCap: 0,
      packages: [],
      targetPlanCode: data.target_plan_code
        ? String(data.target_plan_code)
        : undefined,
      targetPlanName: data.target_plan_name
        ? String(data.target_plan_name)
        : undefined,
    };
  }

  const price = requireNonNegativeNumber(
    data.upgrade_price,
    'COURSE_UPGRADE_PRICE',
  );
  const totalBalance = requireNonNegativeNumber(
    data.total_balance,
    'COURSE_UPGRADE_TOTAL_BALANCE',
  );
  const spendableBalance = requireNonNegativeNumber(
    data.spendable_balance,
    'COURSE_UPGRADE_SPENDABLE_BALANCE',
  );
  const deficit = requireNonNegativeNumber(
    data.deficit,
    'COURSE_UPGRADE_DEFICIT',
  );
  const rewardContributionCap = requireNonNegativeNumber(
    data.reward_contribution_cap_per_course,
    'COURSE_UPGRADE_REWARD_CAP',
  );
  if (
    spendableBalance > totalBalance ||
    deficit !== Math.max(0, price - spendableBalance)
  ) {
    throw new Error('API_CONTRACT_INVALID_COURSE_UPGRADE_QUOTE');
  }
  const targetMessageLimit =
    data.target_message_limit === null ||
    data.target_message_limit === undefined
      ? undefined
      : requireNonNegativeNumber(
          data.target_message_limit,
          'COURSE_UPGRADE_MESSAGE_LIMIT',
        );

  return {
    courseRevision,
    alreadyUpgraded: false,
    chatAvailable: firstBoolean(data.chat_available) ?? false,
    certificateAvailable: firstBoolean(data.certificate_available) ?? false,
    aiIncluded: firstBoolean(data.ai_included) ?? false,
    courseId: data.course_id ? String(data.course_id) : undefined,
    courseTitle: data.course_title ? String(data.course_title) : undefined,
    price,
    totalBalance,
    spendableBalance,
    deficit,
    rewardContributionCap,
    packages: mapFinancialPackages(data.recommended_packages),
    targetPlanCode: data.target_plan_code
      ? String(data.target_plan_code)
      : undefined,
    targetPlanName: data.target_plan_name
      ? String(data.target_plan_name)
      : undefined,
    targetMessageLimit,
  };
};

export const getFullTrackUpgradeQuote = async (
  courseId: string,
): Promise<CourseChatUpgradeQuote> =>
  mapCourseChatUpgradeQuote(
    payload(
      await publicRequest.get(
        `courses/${numericCourseId(courseId)}/full-track-upgrade`,
      ),
    ),
  );

export const purchaseFullTrackUpgrade = async (
  courseId: string,
  targetPlanCode?: string,
  expectedPrice?: number,
  expectedCourseRevision?: number,
): Promise<CourseChatUpgradeQuote> => {
  const boundary = await captureAccountSessionBoundary();
  const courseIdValue = numericCourseId(courseId);
  const normalizedTargetPlan = String(targetPlanCode || '')
    .trim()
    .toLowerCase();
  if (
    !['guided', 'mentor'].includes(normalizedTargetPlan) ||
    !Number.isSafeInteger(expectedPrice) ||
    Number(expectedPrice) < 0 ||
    !Number.isSafeInteger(expectedCourseRevision) ||
    Number(expectedCourseRevision) < 1
  ) {
    throw new Error('COURSE_UPGRADE_INTENT_INVALID');
  }
  const normalizedExpectedPrice = Math.trunc(Number(expectedPrice));
  const upgradeIntent = {
    courseId: courseIdValue,
    targetPlanCode: normalizedTargetPlan,
    expectedPrice: normalizedExpectedPrice,
  };
  const idempotencyKey = await getOrCreateCourseUpgradeAttemptKey(
    upgradeIntent,
    boundary,
  );
  assertAccountSessionBoundary(boundary);
  const result = mapCourseChatUpgradeQuote(
    payload(
      await publicRequest.post(
        `courses/${courseIdValue}/full-track-upgrade`,
        {
          target_plan_code: normalizedTargetPlan,
          expected_price: normalizedExpectedPrice,
          expected_course_revision: expectedCourseRevision,
          idempotency_key: idempotencyKey,
        },
        {headers: {'Idempotency-Key': idempotencyKey}},
      ),
    ),
  );
  assertAccountSessionBoundary(boundary);
  await clearCourseUpgradeAttemptKey(upgradeIntent, idempotencyKey, boundary);
  return result;
};
