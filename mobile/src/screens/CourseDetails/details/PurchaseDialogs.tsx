import React from 'react';
import type {CourseAccessPlan} from '../../../services/roknApi';
import CourseSubscriptionSheet from '../../../components/CourseSubscriptionSheet';
import {CourseCodeEntry} from './CourseCodeEntry';
import type {DialogStep} from './useCoursePurchaseFlow';

export type {DialogStep} from './useCoursePurchaseFlow';
export {CourseRetentionDialog} from './CourseRetentionDialog';

type Props = {
  courseId: string;
  courseRevision?: number;
  onSubscribed: () => void | Promise<void>;
  accessPlans: CourseAccessPlan[];
  codeBusy?: boolean;
  courseTitle: string;
  projectCount?: number;
  courseCode?: string;
  courseCodeEnabled?: boolean;
  grantActivated?: boolean;
  dialogStep: DialogStep;
  notice: string;
  onClose: () => void;
  onCourseCodeChange?: (value: string) => void;
  onRedeemCourseCode?: () => void | Promise<void>;
  onSelectPlan: (plan: CourseAccessPlan) => void;
  onSuccessStart: () => void;
  selectedPlan?: CourseAccessPlan;
};

export function CoursePurchaseDialog(props: Props) {
  return (
    <CourseSubscriptionSheet
      visible={props.dialogStep !== null}
      courseId={props.courseId}
      courseTitle={props.courseTitle}
      courseRevision={props.courseRevision}
      plans={props.accessPlans}
      selectedPlan={props.selectedPlan}
      onSelectPlan={props.onSelectPlan}
      onClose={props.onClose}
      onCompleted={props.onSubscribed}
      onStart={props.onSuccessStart}
      hasProjects={(props.projectCount || 0) > 0}
      success={props.dialogStep === 'success'}
      grantActivated={props.grantActivated}
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
