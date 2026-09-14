import React from 'react';
import type {CoinPackage} from '../../../services/api/coinPackageMapper';
import type {CourseAccessPlan} from '../../../services/roknApi';
import CourseSubscriptionSheet from '../../../components/CourseSubscriptionSheet';
import {CourseCodeEntry} from './PurchaseDialogSteps';
import type {DialogStep} from './useCoursePurchaseFlow';

export type {DialogStep} from './useCoursePurchaseFlow';
export {CourseRetentionDialog} from './CourseRetentionDialog';

type Props = {
  courseId?: string;
  courseRevision?: number;
  onSubscribed?: () => void | Promise<void>;
  accessPlans: CourseAccessPlan[];
  balance: number;
  bottomInset: number;
  busy: boolean;
  codeBusy?: boolean;
  courseTitle: string;
  projectCount?: number;
  courseCode?: string;
  courseCodeEnabled?: boolean;
  couponApplied?: boolean;
  couponBusy?: boolean;
  couponCode?: string;
  couponDiscountAmount?: number;
  dialogStep: DialogStep;
  grantActivated: boolean;
  isTablet: boolean;
  notice: string;
  onBuyCoins: (coinPackage: CoinPackage) => void | Promise<void>;
  onApplyCoupon?: () => void | Promise<void>;
  onCouponCodeChange?: (value: string) => void;
  onChangePlan?: () => void;
  onClose: () => void;
  onConfirmPurchase: () => void | Promise<void>;
  onCourseCodeChange?: (value: string) => void;
  onRedeemCourseCode?: () => void | Promise<void>;
  onSelectPlan: (plan: CourseAccessPlan) => void;
  onSuccessStart: () => void;
  packages: CoinPackage[];
  originalPurchasePrice?: number;
  purchasePrice: number;
  rewardContributionLimit: number;
  rewardContributionPercent: number;
  selectedPlan?: CourseAccessPlan;
  shortfall: number;
  sufficientPackage?: CoinPackage;
  usableCurrentBalance: number;
};

export function CoursePurchaseDialog(props: Props) {
  return (
    <CourseSubscriptionSheet
      visible={props.dialogStep !== null}
      courseId={props.courseId || ''}
      courseTitle={props.courseTitle}
      courseRevision={props.courseRevision}
      plans={props.accessPlans}
      selectedPlan={props.selectedPlan}
      onSelectPlan={props.onSelectPlan}
      onClose={props.onClose}
      onCompleted={props.onSubscribed || (() => undefined)}
      onStart={props.onSuccessStart}
      hasProjects={(props.projectCount || 0) > 0}
      success={props.dialogStep === 'success'}
      externalBusy={props.codeBusy}
      externalNotice={props.notice}
      accessCodeEntry={
        props.courseCodeEnabled
          ? (disabled: boolean) => (
              <CourseCodeEntry
                busy={props.codeBusy || disabled}
                code={props.courseCode || ''}
                onChange={props.onCourseCodeChange || (() => undefined)}
                onRedeem={props.onRedeemCourseCode || (() => undefined)}
              />
            )
          : undefined
      }
    />
  );
}
