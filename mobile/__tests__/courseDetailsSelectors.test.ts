import {
  selectCourseDetailsPresentation,
  selectCourseHeroHeight,
  selectCoursePurchaseEntryStep,
} from '../src/screens/CourseDetails/details/selectors';
import type {CourseAccessPlan, CourseDetails} from '../src/services/roknApi';

const plan = (code: string, priceCoins: number): CourseAccessPlan => ({
  code,
  name: code,
  priceCoins,
  chatEnabled: code !== 'basic',
  chatMessageLimit: code === 'mentor' ? 80 : 0,
  projectFeedbackLevel: code === 'mentor' ? 'enhanced' : 'pass_only',
  projectReportEnabled: code === 'mentor',
  projectOutputEnabled: code === 'mentor',
  certificateEnabled: true,
});

const course: CourseDetails = {
  id: '42',
  publishedRevision: 1,
  title: 'كورس الإنتاج',
  description: 'وصف الكورس',
  price: 300,
  instructor: 'مدرب ركن',
  instructorBio: '',
  owned: false,
  started: false,
  modules: [],
  reelCount: 18,
  projectCount: 2,
  previewReelCount: 3,
  ratingAverage: 4.8,
  ratingsCount: 91,
  userRating: null,
  studentsCount: 640,
  durationMinutes: 125,
  accessPlans: [plan('basic', 300), plan('mentor', 700)],
};

const presentation = (
  overrides: Partial<
    Parameters<typeof selectCourseDetailsPresentation>[0]
  > = {},
) =>
  selectCourseDetailsPresentation({
    remoteBalance: 650,
    remoteCommerceLoading: false,
    remoteCourse: course,
    remoteError: '',
    remoteLoading: false,
    remotePackages: [
      {id: 'large', coins: 1000, price: 500, label: 'كبيرة'},
      {id: 'small', coins: 250, price: 150, label: 'صغيرة'},
    ],
    remoteSession: true,
    remoteSpendableBalance: 500,
    selectedPlanCode: 'mentor',
    ...overrides,
  });

describe('course details presentation contract', () => {
  it('preserves production metadata and the selected pricing tier', () => {
    const result = presentation();

    expect(result).toMatchObject({
      courseTitle: course.title,
      courseDescription: course.description,
      reelCount: 18,
      projectCount: 2,
      previewReelCount: 3,
      ratingAverage: 4.8,
      ratingsCount: 91,
      studentsCount: 640,
      durationMinutes: 125,
      coursePrice: 300,
      purchasePrice: 700,
      shortfall: 200,
      pageReady: true,
    });
    expect(result.selectedPlan?.code).toBe('mentor');
  });

  it('keeps the free sample available to guests without exposing purchase', () => {
    const guestSample = presentation({remoteSession: false});
    expect(guestSample.primaryActionLabel).toBe('شاهد مجانًا');
    expect(guestSample.primaryAction.kind).toBe('preview');
    expect(guestSample.showSecondaryPreview).toBe(false);
    expect(presentation({remoteSession: true}).showSecondaryPreview).toBe(true);
    expect(
      presentation({
        remoteCourse: {...course, previewReelCount: 0},
        remoteSession: false,
      }).primaryAction.kind,
    ).toBe('login');
    expect(
      presentation({
        remoteCourse: {...course, previewReelCount: 0},
        remoteSession: false,
      }).primaryActionLabel,
    ).toContain('سجّل الدخول');
    expect(
      presentation({
        remoteCourse: {...course, owned: true, started: true},
      }).primaryActionLabel,
    ).toBe('استكمل الكورس');
    expect(
      presentation({
        remoteCourse: {...course, owned: true, started: false},
      }).primaryAction,
    ).toEqual({kind: 'start', label: 'ابدأ الكورس'});
    expect(presentation().primaryActionLabel).toBe('اختر الفئة المناسبة لك');
  });

  it('derives ownership only from the course entitlement snapshot', () => {
    expect(presentation({remoteCourse: {...course, owned: true}}).owned).toBe(
      true,
    );
    expect(presentation({remoteCourse: {...course, owned: false}}).owned).toBe(
      false,
    );
  });

  it('uses one entry decision for plan selection, top-up and confirmation', () => {
    expect(
      selectCoursePurchaseEntryStep({
        forcePlanSelection: true,
        purchasePrice: 300,
        spendableBalance: 500,
      }),
    ).toBe('plans');
    expect(
      selectCoursePurchaseEntryStep({
        forcePlanSelection: false,
        purchasePrice: 300,
        spendableBalance: 299,
      }),
    ).toBe('topup');
    expect(
      selectCoursePurchaseEntryStep({
        forcePlanSelection: false,
        purchasePrice: 300,
        spendableBalance: 300,
      }),
    ).toBe('confirm');
  });

  it('never exposes pricing tiers or educational access codes to a guest', () => {
    expect(presentation({remoteSession: false}).canChooseAccess).toBe(false);
    expect(presentation({remoteSession: null}).canChooseAccess).toBe(false);
    expect(presentation({remoteSession: true}).canChooseAccess).toBe(true);
    expect(
      presentation({remoteCourse: {...course, owned: true}}).canChooseAccess,
    ).toBe(false);
  });

  it('uses one decision for the CTA label and behavior when plans include a free tier', () => {
    const result = presentation({
      remoteCourse: {
        ...course,
        price: 0,
        accessPlans: [plan('basic', 0), plan('mentor', 700)],
      },
      selectedPlanCode: 'basic',
    });

    expect(result.primaryAction).toEqual({
      kind: 'choose_plan',
      label: 'اختر الفئة المناسبة لك',
    });
  });

  it('does not present a purchasable action while wallet ownership is unknown', () => {
    expect(presentation({remoteBalance: null}).primaryAction.kind).toBe(
      'wallet_unavailable',
    );
  });

  it('keeps the definition visible but disables its single CTA while commerce is loading', () => {
    const result = presentation({remoteCommerceLoading: true});

    expect(result.pageReady).toBe(true);
    expect(result.primaryAction).toEqual({
      kind: 'disabled',
      label: 'جارٍ تجهيز الشراء',
    });
    expect(result.primaryActionDisabled).toBe(true);
  });

  it('sorts package choices without mutating API data', () => {
    const packages = [
      {id: 'large', coins: 1000, price: 500, label: 'كبيرة'},
      {id: 'small', coins: 250, price: 150, label: 'صغيرة'},
    ];

    const result = presentation({remotePackages: packages});

    expect(result.packages.map(item => item.id)).toEqual(['small', 'large']);
    expect(result.checkoutPackages.map(item => item.id)).toEqual([
      'small',
      'large',
    ]);
    expect(packages.map(item => item.id)).toEqual(['large', 'small']);
  });

  it('marks the smallest sufficient top-up and never offers a partial package', () => {
    expect(presentation().sufficientPackage?.id).toBe('small');
    const insufficient = presentation({
      remoteSpendableBalance: 0,
      remotePackages: [
        {id: 'too-small', coins: 250, price: 150, label: 'صغيرة'},
      ],
    });
    expect(insufficient.sufficientPackage).toBeUndefined();
    expect(insufficient.checkoutPackages).toEqual([]);
  });

  it('keeps reward coins visible but excludes the part above the selected plan discount', () => {
    const result = presentation({
      remoteBalance: 650,
      remotePaidBalance: 100,
      remoteRewardBalance: 550,
      remoteSpendableBalance: 500,
      remoteCourse: {
        ...course,
        accessPlans: course.accessPlans.map(item =>
          item.code === 'mentor' ? {...item, minimumPaidCoins: 400} : item,
        ),
      },
    });

    expect(result.balance).toBe(650);
    expect(result.spendableBalance).toBe(400);
    expect(result.shortfall).toBe(300);
    expect(result.planSpendableBalances.mentor).toBe(400);
  });

  it('keeps the responsive hero height bounded by the viewport', () => {
    expect(
      selectCourseHeroHeight({
        width: 400,
        height: 800,
        isTablet: false,
        fontScale: 1,
      }),
    ).toBe(352);
    expect(
      selectCourseHeroHeight({
        width: 1200,
        height: 900,
        isTablet: true,
        fontScale: 2,
      }),
    ).toBeLessThanOrEqual(648);
  });
});
