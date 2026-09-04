import {useCallback, useReducer} from 'react';
import {selectCoursePurchaseEntryStep} from './selectors';

export type DialogStep = 'plans' | 'topup' | 'confirm' | 'success' | null;
export type PurchaseFlowTerms = {
  forcePlanSelection: boolean;
  purchasePrice: number;
  spendableBalance: number;
};

export type PurchaseFlowState = {
  step: DialogStep;
  selectedPlanCode: string;
  restoredPlanKey: string;
};

export type PurchaseFlowAction =
  | {type: 'reset'}
  | {type: 'ensure_plan'; planCode: string}
  | {type: 'restore_plan'; planCode: string; restoreKey: string}
  | {type: 'select_plan'; planCode: string; nextStep: Exclude<DialogStep, null>}
  | {type: 'show'; step: Exclude<DialogStep, null>}
  | {type: 'close'};

export const initialCoursePurchaseFlowState: PurchaseFlowState = {
  step: null,
  selectedPlanCode: '',
  restoredPlanKey: '',
};

export const coursePurchaseFlowReducer = (
  state: PurchaseFlowState,
  action: PurchaseFlowAction,
): PurchaseFlowState => {
  switch (action.type) {
    case 'reset':
      return initialCoursePurchaseFlowState;
    case 'ensure_plan':
      return state.selectedPlanCode === action.planCode
        ? state
        : {...state, selectedPlanCode: action.planCode};
    case 'restore_plan':
      return {
        ...state,
        selectedPlanCode: action.planCode,
        restoredPlanKey: action.restoreKey,
      };
    case 'select_plan':
      return {
        ...state,
        selectedPlanCode: action.planCode,
        step: action.nextStep,
      };
    case 'show':
      return state.step === action.step ? state : {...state, step: action.step};
    case 'close':
      return state.step === null ? state : {...state, step: null};
  }
};

export function useCoursePurchaseFlow() {
  const [state, dispatch] = useReducer(
    coursePurchaseFlowReducer,
    initialCoursePurchaseFlowState,
  );

  const reset = useCallback(() => dispatch({type: 'reset'}), []);
  const ensurePlan = useCallback(
    (planCode: string) => dispatch({type: 'ensure_plan', planCode}),
    [],
  );
  const restorePlan = useCallback((planCode: string, restoreKey: string) => {
    dispatch({type: 'restore_plan', planCode, restoreKey});
  }, []);
  const openForTerms = useCallback(
    (terms: PurchaseFlowTerms) => {
      dispatch({
        type: 'show',
        step: selectCoursePurchaseEntryStep(terms),
      });
    },
    [],
  );
  const selectPlanForTerms = useCallback(
    (
      planCode: string,
      terms: {purchasePrice: number; spendableBalance: number},
    ) => {
      dispatch({
        type: 'select_plan',
        planCode,
        nextStep: selectCoursePurchaseEntryStep({
          forcePlanSelection: false,
          ...terms,
        }),
      });
    },
    [],
  );
  const showPlans = useCallback(() => dispatch({type: 'show', step: 'plans'}), []);
  const showTopup = useCallback(() => dispatch({type: 'show', step: 'topup'}), []);
  const showConfirm = useCallback(
    () => dispatch({type: 'show', step: 'confirm'}),
    [],
  );
  const showSuccess = useCallback(
    () => dispatch({type: 'show', step: 'success'}),
    [],
  );
  const close = useCallback(() => dispatch({type: 'close'}), []);

  return {
    close,
    ensurePlan,
    openForTerms,
    reset,
    restorePlan,
    selectPlanForTerms,
    selectedPlanCode: state.selectedPlanCode,
    showConfirm,
    showPlans,
    showSuccess,
    showTopup,
    step: state.step,
    restoredPlanKey: state.restoredPlanKey,
  };
}
