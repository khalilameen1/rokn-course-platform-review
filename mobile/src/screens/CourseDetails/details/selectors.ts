import {CAN_START_COIN_CHECKOUT} from '../../../constants/distribution';
import {formatArabicNumber} from '../../../constants/arabicFormatting';
import type {CoinPackage} from '../../../services/api/coinPackageMapper';
import type {
  CourseAccessPlan,
  CourseDetails as CourseDetailsDto,
} from '../../../services/roknApi';
import {derivePurchaseTerms} from './purchaseTerms';

export const planBenefits = (
  plan: CourseAccessPlan,
  hasProjects: boolean,
): string[] => {
  const items = ['الكورس كامل'];
  if (!plan.chatEnabled) {
    items.push('دون استفسارات');
  } else {
    items.push(
      `${formatArabicNumber(plan.chatMessageLimit)} رسالة للاستفسارات`,
    );
  }
  if (hasProjects) {
    items.push(
      plan.projectReportEnabled
        ? 'تقرير بملاحظات على كل مشروع'
        : 'مشروعات دون تقرير',
    );
    if (
      plan.projectFollowupEnabled &&
      (plan.projectFollowupMessageLimit ?? 0) > 0
    ) {
      items.push(
        `${formatArabicNumber(
          plan.projectFollowupMessageLimit ?? 0,
        )} رسالة لمناقشة مشروعاتك`,
      );
    }
  }
  if (plan.certificateEnabled) items.push('شهادة عند إتمام الكورس');
  return items;
};

type CourseDetailsPresentationInput = {
  remoteBalance: number | null;
  remoteCommerceLoading: boolean;
  remoteCourse: CourseDetailsDto | null;
  remoteError: string;
  remoteLoading: boolean;
  remotePaidBalance?: number | null;
  remotePackages: CoinPackage[];
  remoteSession: boolean | null;
  remoteRewardBalance?: number | null;
  remoteRewardContributionCap?: number | null;
  remoteSpendableBalance: number | null;
  selectedPlanCode: string;
};

export const selectCourseDetailsPresentation = ({
  remoteBalance,
  remoteCommerceLoading,
  remoteCourse,
  remoteError,
  remoteLoading,
  remotePaidBalance = null,
  remotePackages,
  remoteSession,
  remoteRewardBalance = null,
  remoteRewardContributionCap = null,
  remoteSpendableBalance,
  selectedPlanCode,
}: CourseDetailsPresentationInput) => {
  const baseCoursePrice = remoteCourse?.price ?? null;
  const accessPlans: CourseAccessPlan[] = remoteCourse?.accessPlans || [];
  const selectedPlan =
    accessPlans.find(plan => plan.code === selectedPlanCode) || accessPlans[0];
  const coursePrice = accessPlans.length
    ? Math.min(...accessPlans.map(plan => plan.priceCoins))
    : baseCoursePrice;
  const courseTitle = remoteCourse?.title || 'كورس ركن';
  const courseDescription = remoteCourse?.description || '';
  const reelCount = remoteCourse?.reelCount || 0;
  const projectCount = remoteCourse?.projectCount || 0;
  const previewReelCount = remoteCourse?.previewReelCount || 0;
  const hasPreview = previewReelCount > 0;

  // Course details is the only entitlement snapshot on this screen. Keeping
  // a second ownership boolean lets a late read turn a completed purchase
  // back into a buy CTA (or the reverse).
  const owned = remoteCourse?.owned === true;
  const balance = remoteBalance ?? 0;
  const paidBalance = remotePaidBalance ?? 0;
  const rewardBalance =
    remoteRewardBalance ?? Math.max(0, balance - paidBalance);
  const genericRewardAllowance = Math.max(
    0,
    remoteRewardContributionCap ??
      (remoteSpendableBalance ?? paidBalance) - paidBalance,
  );
  const purchasePrice = selectedPlan?.priceCoins ?? coursePrice ?? 0;
  const terms = derivePurchaseTerms({
    balance,
    minimumPaidCoins: selectedPlan?.minimumPaidCoins ?? 0,
    packages: remotePackages,
    paidBalance,
    price: purchasePrice,
    rewardBalance,
    rewardContributionLimit: genericRewardAllowance,
  });
  const planSpendableBalances = Object.fromEntries(
    accessPlans.map(plan => [
      plan.code,
      derivePurchaseTerms({
        balance,
        minimumPaidCoins: plan.minimumPaidCoins ?? 0,
        packages: [],
        paidBalance,
        price: plan.priceCoins,
        rewardBalance,
        rewardContributionLimit: genericRewardAllowance,
      }).spendableBalance,
    ]),
  ) as Record<string, number>;
  const {
    packages,
    rewardContributionLimit,
    rewardContributionPercent,
    shortfall,
    spendableBalance,
    sufficientPackage,
    sufficientPackages: checkoutPackages,
    usableCurrentBalance,
  } = terms;
  const pageReady = Boolean(remoteCourse) && !remoteLoading;
  const started = owned && remoteCourse?.started === true;
  const primaryAction = remoteError
    ? ({kind: 'disabled', label: 'تعذّر تحميل التفاصيل'} as const)
    : !pageReady || remoteSession === null
    ? ({kind: 'disabled', label: 'جارٍ تجهيز الكورس'} as const)
    : owned
    ? started
      ? ({kind: 'resume', label: 'استكمل الكورس'} as const)
      : ({kind: 'start', label: 'ابدأ الكورس'} as const)
    : remoteSession === false && hasPreview
    ? ({kind: 'preview', label: 'شاهد مجانًا'} as const)
    : remoteSession === false
    ? ({kind: 'login', label: 'سجّل الدخول لفتح الكورس'} as const)
    : coursePrice === null
    ? ({kind: 'price_unavailable', label: 'السعر لم يُنشر بعد'} as const)
    : !CAN_START_COIN_CHECKOUT && hasPreview
    ? ({kind: 'preview', label: 'شاهد مجانًا'} as const)
    : !CAN_START_COIN_CHECKOUT
    ? ({kind: 'checkout_unavailable', label: 'الشراء غير متاح الآن'} as const)
    : coursePrice > 0 && remoteCommerceLoading
    ? ({kind: 'disabled', label: 'جارٍ تجهيز الشراء'} as const)
    : coursePrice > 0 && remoteBalance === null
    ? ({kind: 'wallet_unavailable', label: 'شراء الكورس'} as const)
    : accessPlans.length > 1
    ? ({kind: 'choose_plan', label: 'اختر الفئة المناسبة لك'} as const)
    : coursePrice === 0
    ? ({kind: 'free', label: 'ابدأ التعلّم مجانًا'} as const)
    : ({kind: 'purchase', label: 'شراء الكورس'} as const);
  const canChooseAccess =
    !owned && pageReady && !remoteError && remoteSession === true;
  const showSecondaryPreview =
    !owned &&
    hasPreview &&
    pageReady &&
    CAN_START_COIN_CHECKOUT &&
    primaryAction.kind !== 'preview';
  const ratingsCount = remoteCourse?.ratingsCount ?? 0;
  const ratingAverage = remoteCourse?.ratingAverage ?? null;
  const studentsCount = remoteCourse?.studentsCount ?? 0;
  const durationMinutes = remoteCourse?.durationMinutes ?? null;

  return {
    accessPlans,
    balance,
    canChooseAccess,
    courseDescription,
    coursePrice,
    courseTitle,
    checkoutPackages,
    durationMinutes,
    hasPreview,
    owned,
    packages,
    planSpendableBalances,
    pageReady,
    previewReelCount,
    primaryAction,
    primaryActionDisabled: primaryAction.kind === 'disabled',
    primaryActionLabel: primaryAction.label,
    projectCount,
    purchasePrice,
    rewardContributionLimit,
    rewardContributionPercent,
    ratingAverage,
    ratingsCount,
    reelCount,
    selectedPlan,
    showSecondaryPreview,
    shortfall,
    spendableBalance,
    started,
    usableCurrentBalance,
    studentsCount,
    sufficientPackage,
  };
};

export type CoursePrimaryAction = ReturnType<
  typeof selectCourseDetailsPresentation
>['primaryAction'];

export const selectCourseHeroHeight = ({
  fontScale,
  height,
  isTablet,
  width,
}: {
  fontScale: number;
  height: number;
  isTablet: boolean;
  width: number;
}) => {
  const heroBaseHeight = Math.max(310, width * (isTablet ? 0.48 : 0.88));
  return Math.min(
    height * 0.72,
    heroBaseHeight + Math.max(0, fontScale - 1) * 150,
  );
};

export type CoursePurchaseEntryStep = 'plans' | 'topup' | 'confirm';

export const selectCoursePurchaseEntryStep = ({
  forcePlanSelection,
  purchasePrice,
  spendableBalance,
}: {
  forcePlanSelection: boolean;
  purchasePrice: number;
  spendableBalance: number;
}): CoursePurchaseEntryStep =>
  forcePlanSelection
    ? 'plans'
    : spendableBalance >= purchasePrice
    ? 'confirm'
    : 'topup';
