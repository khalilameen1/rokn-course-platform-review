import {planBenefits} from '../src/screens/CourseDetails/details/selectors';
import type {CourseAccessPlan} from '../src/services/roknApi';

const plan: CourseAccessPlan = {
  code: 'guided',
  name: 'التعلّم بإرشاد',
  priceCoins: 650,
  minimumPaidCoins: 325,
  chatEnabled: true,
  chatMessageLimit: 40,
  projectFeedbackLevel: 'report',
  projectReportEnabled: true,
  projectFollowupEnabled: false,
  projectOutputEnabled: false,
  certificateEnabled: true,
};

describe('course plan benefits', () => {
  it('does not sell project benefits in a course without projects', () => {
    expect(planBenefits(plan, false)).toEqual([
      'الكورس كامل',
      '٤٠ رسالة للاستفسارات',
      'شهادة عند إتمام الكورس',
    ]);
  });

  it('distinguishes a report from a conversation using effective capabilities', () => {
    const reportOnly = planBenefits(plan, true);
    expect(reportOnly).toContain('تقرير بملاحظات على كل مشروع');
    expect(reportOnly.some(item => item.includes('لمناقشة'))).toBe(false);
    expect(
      planBenefits(
        {
          ...plan,
          projectFollowupEnabled: true,
          projectFollowupMessageLimit: 15,
        },
        true,
      ),
    ).toContain('١٥ رسالة لمناقشة مشروعاتك');
  });

  it('does not promise a report from the plan tier name alone', () => {
    const basic = planBenefits(
      {
        ...plan,
        projectFeedbackLevel: 'enhanced',
        projectReportEnabled: false,
        chatEnabled: false,
        certificateEnabled: false,
      },
      true,
    );
    expect(basic).toEqual([
      'الكورس كامل',
      'دون استفسارات',
      'مشروعات دون تقرير',
    ]);
  });

  it('leaves reward eligibility to the checkout quote instead of advertising a second cap', () => {
    expect(planBenefits(plan, true).some(item => item.includes('مكافآت'))).toBe(
      false,
    );
  });
});
