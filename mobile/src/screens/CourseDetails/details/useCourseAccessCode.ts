import {useCallback, useEffect, useRef, useState} from 'react';
import type {Dispatch, SetStateAction} from 'react';
import {CAN_REDEEM_COURSE_ACCESS_CODE} from '../../../constants/distribution';
import {redeemCourseCode} from '../../../services/roknApi';
import {trackProductEvent} from '../../../services/productAnalytics';
import {learnerErrorMessage} from '../../../utils/errorPayload';
import {normalizeHumanIdentifier} from '../../../utils/unicodeText';

type Params = {
  checkoutBusy: boolean;
  courseId: string;
  identityKey: string;
  openLogin: () => void;
  session: boolean | null;
  closePurchase: () => void;
  showSuccess: () => void;
  setNotice: Dispatch<SetStateAction<string>>;
  setOwned: (owned: boolean) => void;
};

export function useCourseAccessCode({
  checkoutBusy,
  courseId,
  identityKey,
  openLogin,
  session,
  closePurchase,
  showSuccess,
  setNotice,
  setOwned,
}: Params) {
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [grantActivated, setGrantActivated] = useState(false);
  const activeScopeRef = useRef({courseId, identityKey});
  const generationRef = useRef(0);
  const inFlightRef = useRef(false);

  activeScopeRef.current = {courseId, identityKey};

  const ownsOperation = useCallback(
    (expectedCourseId: string, expectedIdentity: string, generation: number) =>
      activeScopeRef.current.courseId === expectedCourseId &&
      activeScopeRef.current.identityKey === expectedIdentity &&
      generationRef.current === generation,
    [],
  );

  useEffect(() => {
    generationRef.current += 1;
    inFlightRef.current = false;
    setCode('');
    setBusy(false);
    setGrantActivated(false);
    return () => {
      generationRef.current += 1;
    };
  }, [courseId, identityKey]);

  const redeem = useCallback(async () => {
    if (!CAN_REDEEM_COURSE_ACCESS_CODE || checkoutBusy || inFlightRef.current) {
      return;
    }
    setGrantActivated(false);
    const normalizedCode = normalizeHumanIdentifier(code);
    if (!normalizedCode) {
      setNotice('اكتب الكود أولًا');
      return;
    }
    if (session === false) {
      closePurchase();
      openLogin();
      return;
    }
    const operationCourseId = courseId;
    const operationIdentity = identityKey;
    const operationGeneration = generationRef.current;
    inFlightRef.current = true;
    setBusy(true);
    setNotice('');
    try {
      const result = await redeemCourseCode(normalizedCode, operationCourseId);
      if (
        !ownsOperation(
          operationCourseId,
          operationIdentity,
          operationGeneration,
        )
      ) {
        return;
      }
      if (result.courseId && result.courseId !== operationCourseId) {
        setNotice(
          result.courseName
            ? `هذا الكود مخصص لكورس «${result.courseName}»`
            : 'هذا الكود مخصص لكورس آخر',
        );
        return;
      }
      setOwned(true);
      if (!result.alreadyEnrolled) {
        void trackProductEvent({
          event_name: 'grant_claimed',
          screen_key: 'course_details',
          course_id: operationCourseId,
        });
      }
      setGrantActivated(
        result.accessType === 'scholarship' && !result.alreadyEnrolled,
      );
      setCode('');
      showSuccess();
    } catch (error: unknown) {
      if (
        !ownsOperation(
          operationCourseId,
          operationIdentity,
          operationGeneration,
        )
      ) {
        return;
      }
      setNotice(
        learnerErrorMessage(
          error,
          'تعذّر تأكيد تفعيل الكود\nحدّث الصفحة قبل المحاولة مرة أخرى',
        ),
      );
    } finally {
      if (
        ownsOperation(operationCourseId, operationIdentity, operationGeneration)
      ) {
        inFlightRef.current = false;
        setBusy(false);
      }
    }
  }, [
    checkoutBusy,
    code,
    courseId,
    identityKey,
    openLogin,
    ownsOperation,
    session,
    closePurchase,
    showSuccess,
    setNotice,
    setOwned,
  ]);

  return {
    busy,
    code,
    grantActivated,
    redeem,
    setCode,
  };
}
