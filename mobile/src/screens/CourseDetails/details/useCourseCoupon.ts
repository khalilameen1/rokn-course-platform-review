import {useCallback, useEffect, useRef, useState} from 'react';
import type {Dispatch, SetStateAction} from 'react';
import type {CoinPackage} from '../../../services/api/coinPackageMapper';
import {
  quoteCoursePurchase,
  type CourseAccessPlan,
  type CoursePurchaseQuote,
} from '../../../services/roknApi';
import type {CourseDetailsRouteParams} from '../../../navigation/types';
import {learnerErrorMessage} from '../../../utils/errorPayload';
import {normalizeHumanIdentifier} from '../../../utils/unicodeText';
import {derivePurchaseTerms} from './purchaseTerms';

type Params = {
  balance: number;
  courseId: string;
  identityKey: string;
  originalPrice: number;
  packages: CoinPackage[];
  pageReady: boolean;
  paidBalance: number | null;
  publishedRevision?: number;
  rewardBalance: number | null;
  rewardContributionLimit: number;
  routeParams: CourseDetailsRouteParams;
  selectedPlan?: CourseAccessPlan;
  session: boolean | null;
  showConfirm: () => void;
  showTopup: () => void;
  setNotice: Dispatch<SetStateAction<string>>;
};

type RestoreState = {
  key: string;
  status: 'quoting' | 'ready' | 'failed';
};

export function useCourseCoupon({
  balance,
  courseId,
  identityKey,
  originalPrice,
  packages,
  pageReady,
  paidBalance,
  publishedRevision,
  rewardBalance,
  rewardContributionLimit,
  routeParams,
  selectedPlan,
  session,
  showConfirm,
  showTopup,
  setNotice,
}: Params) {
  const [code, setCode] = useState('');
  const [quote, setQuote] = useState<CoursePurchaseQuote | null>(null);
  const [busy, setBusy] = useState(false);
  const [restoreState, setRestoreState] = useState<RestoreState | null>(null);
  const requestRef = useRef('');
  const quoteEpochRef = useRef(0);
  const activeScopeRef = useRef({courseId, identityKey});
  const codeRef = useRef(code);
  const selectedPlanCodeRef = useRef(selectedPlan?.code || '');
  const previousPlanCodeRef = useRef(selectedPlan?.code || '');

  activeScopeRef.current = {courseId, identityKey};
  codeRef.current = code;
  selectedPlanCodeRef.current = selectedPlan?.code || '';

  const ownsScope = useCallback(
    (expectedCourseId: string, expectedIdentity: string) =>
      activeScopeRef.current.courseId === expectedCourseId &&
      activeScopeRef.current.identityKey === expectedIdentity,
    [],
  );

  const invalidate = useCallback((clearCode: boolean) => {
    quoteEpochRef.current += 1;
    requestRef.current = '';
    setQuote(null);
    setRestoreState(null);
    setBusy(false);
    if (clearCode) setCode('');
  }, []);

  useEffect(() => {
    quoteEpochRef.current += 1;
    requestRef.current = '';
    previousPlanCodeRef.current = '';
    setCode('');
    setQuote(null);
    setBusy(false);
    setRestoreState(null);
  }, [courseId, identityKey]);

  useEffect(() => {
    const previousPlanCode = previousPlanCodeRef.current;
    const nextPlanCode = selectedPlan?.code || '';
    previousPlanCodeRef.current = nextPlanCode;
    invalidate(true);
    if (previousPlanCode && previousPlanCode !== nextPlanCode) setNotice('');
  }, [invalidate, selectedPlan?.code, setNotice]);

  const applied = Boolean(
    quote &&
      quote.accessPlanCode === selectedPlan?.code &&
      quote.courseRevision === publishedRevision &&
      quote.originalPrice === originalPrice &&
      quote.couponCode === normalizeHumanIdentifier(code),
  );
  const effectivePrice = applied
    ? quote?.finalPrice ?? originalPrice
    : originalPrice;

  const restoreKey = [
    identityKey,
    courseId,
    routeParams.openPurchase ? 'purchase' : 'closed',
    String(routeParams.purchasePlanCode || '').trim(),
    normalizeHumanIdentifier(routeParams.purchaseCouponCode),
    selectedPlan?.code || '',
  ].join('|');
  const restoreStatus: 'idle' | 'quoting' | 'ready' | 'failed' =
    restoreState?.key === restoreKey ? restoreState.status : 'idle';

  useEffect(() => {
    requestRef.current = '';
    return () => {
      requestRef.current = '';
    };
  }, [restoreKey]);

  const apply = useCallback(async () => {
    const normalized = normalizeHumanIdentifier(code);
    const operationPlan = selectedPlan;
    if (!normalized || busy || !operationPlan) return;
    const operationCourseId = courseId;
    const operationIdentity = identityKey;
    const quoteEpoch = ++quoteEpochRef.current;
    setBusy(true);
    setNotice('');
    try {
      const nextQuote = await quoteCoursePurchase(
        operationCourseId,
        operationPlan.code,
        normalized,
        publishedRevision,
      );
      if (
        !ownsScope(operationCourseId, operationIdentity) ||
        quoteEpochRef.current !== quoteEpoch ||
        selectedPlanCodeRef.current !== operationPlan.code ||
        normalizeHumanIdentifier(codeRef.current) !== normalized
      ) {
        return;
      }
      setCode(nextQuote.couponCode);
      setQuote(nextQuote);
      const terms = derivePurchaseTerms({
        balance,
        minimumPaidCoins: operationPlan.minimumPaidCoins ?? 0,
        packages,
        paidBalance,
        price: nextQuote.finalPrice,
        rewardBalance,
        rewardContributionLimit,
      });
      if (terms.spendableBalance >= nextQuote.finalPrice) showConfirm();
      else showTopup();
    } catch (error: unknown) {
      if (
        !ownsScope(operationCourseId, operationIdentity) ||
        quoteEpochRef.current !== quoteEpoch ||
        selectedPlanCodeRef.current !== operationPlan.code ||
        normalizeHumanIdentifier(codeRef.current) !== normalized
      ) {
        return;
      }
      setQuote(null);
      setNotice(learnerErrorMessage(error, 'الكود غير صحيح أو انتهت صلاحيته'));
    } finally {
      if (
        ownsScope(operationCourseId, operationIdentity) &&
        quoteEpochRef.current === quoteEpoch
      ) {
        setBusy(false);
      }
    }
  }, [
    balance,
    busy,
    code,
    courseId,
    identityKey,
    ownsScope,
    packages,
    paidBalance,
    publishedRevision,
    rewardBalance,
    rewardContributionLimit,
    selectedPlan,
    showConfirm,
    showTopup,
    setNotice,
  ]);

  useEffect(() => {
    const resumedCoupon = normalizeHumanIdentifier(
      routeParams.purchaseCouponCode,
    );
    const resumedPlanCode = String(routeParams.purchasePlanCode || '').trim();
    if (
      !routeParams.openPurchase ||
      !resumedCoupon ||
      restoreStatus !== 'idle' ||
      !pageReady ||
      session !== true ||
      !selectedPlan ||
      (resumedPlanCode && selectedPlan.code !== resumedPlanCode)
    ) {
      return;
    }
    const requestKey = restoreKey;
    const operationCourseId = courseId;
    const operationIdentity = identityKey;
    const operationPlanCode = selectedPlan.code;
    const quoteEpoch = ++quoteEpochRef.current;
    requestRef.current = requestKey;
    setRestoreState({key: requestKey, status: 'quoting'});
    setCode(resumedCoupon);
    setBusy(true);
    void quoteCoursePurchase(
      operationCourseId,
      operationPlanCode,
      resumedCoupon,
      publishedRevision,
    )
      .then(nextQuote => {
        if (
          !ownsScope(operationCourseId, operationIdentity) ||
          requestRef.current !== requestKey ||
          quoteEpochRef.current !== quoteEpoch ||
          selectedPlanCodeRef.current !== operationPlanCode
        ) {
          return;
        }
        setCode(nextQuote.couponCode);
        setQuote(nextQuote);
        setRestoreState({key: requestKey, status: 'ready'});
      })
      .catch(error => {
        if (
          !ownsScope(operationCourseId, operationIdentity) ||
          requestRef.current !== requestKey ||
          quoteEpochRef.current !== quoteEpoch ||
          selectedPlanCodeRef.current !== operationPlanCode
        ) {
          return;
        }
        setQuote(null);
        setRestoreState({key: requestKey, status: 'failed'});
        setNotice(
          learnerErrorMessage(error, 'تعذّر إعادة حساب الخصم\nراجعه ثم حاول'),
        );
      })
      .finally(() => {
        if (
          ownsScope(operationCourseId, operationIdentity) &&
          requestRef.current === requestKey &&
          quoteEpochRef.current === quoteEpoch
        ) {
          setBusy(false);
        }
      });
  }, [
    courseId,
    identityKey,
    ownsScope,
    pageReady,
    publishedRevision,
    restoreKey,
    restoreStatus,
    routeParams.openPurchase,
    routeParams.purchaseCouponCode,
    routeParams.purchasePlanCode,
    selectedPlan,
    session,
    setNotice,
  ]);

  const changeCode = useCallback(
    (value: string) => {
      invalidate(false);
      setCode(normalizeHumanIdentifier(value));
      setNotice('');
    },
    [invalidate, setNotice],
  );

  const replaceQuote = useCallback((nextQuote: CoursePurchaseQuote | null) => {
    quoteEpochRef.current += 1;
    requestRef.current = '';
    setRestoreState(null);
    setQuote(nextQuote);
    if (nextQuote?.couponCode) setCode(nextQuote.couponCode);
  }, []);

  return {
    applied,
    apply,
    busy,
    changeCode,
    code,
    discountAmount: applied ? quote?.discountAmount ?? 0 : 0,
    effectivePrice,
    invalidate,
    quote,
    replaceQuote,
    restoreStatus,
  };
}
