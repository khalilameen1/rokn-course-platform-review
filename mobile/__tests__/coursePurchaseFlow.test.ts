import {
  coursePurchaseFlowReducer,
  initialCoursePurchaseFlowState,
} from '../src/screens/CourseDetails/details/useCoursePurchaseFlow';

describe('course purchase flow', () => {
  it('selects one plan and advances from its current terms', () => {
    const needsTopup = coursePurchaseFlowReducer(
      initialCoursePurchaseFlowState,
      {
        type: 'select_plan',
        planCode: 'guided',
        nextStep: 'topup',
      },
    );
    expect(needsTopup).toEqual({
      restoredPlanKey: '',
      selectedPlanCode: 'guided',
      step: 'topup',
    });

    expect(
      coursePurchaseFlowReducer(needsTopup, {
        type: 'show',
        step: 'confirm',
      }),
    ).toEqual({...needsTopup, step: 'confirm'});
  });

  it('restores a plan without pretending that checkout started', () => {
    expect(
      coursePurchaseFlowReducer(initialCoursePurchaseFlowState, {
        type: 'restore_plan',
        planCode: 'mentor',
        restoreKey: '52|mentor',
      }),
    ).toEqual({
      restoredPlanKey: '52|mentor',
      selectedPlanCode: 'mentor',
      step: null,
    });
  });

  it('closes and resets the whole purchase scope explicitly', () => {
    const success = {
      restoredPlanKey: '52|mentor',
      selectedPlanCode: 'mentor',
      step: 'success' as const,
    };
    expect(coursePurchaseFlowReducer(success, {type: 'close'}).step).toBeNull();
    expect(coursePurchaseFlowReducer(success, {type: 'reset'})).toBe(
      initialCoursePurchaseFlowState,
    );
  });
});
