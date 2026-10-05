import {useCallback, useEffect, useRef, useState} from 'react';
import {AppState} from 'react-native';
import {
  captureAccountSessionBoundary,
  assertAccountSessionBoundary,
} from '../constants/helpers';
import {getCoinPackages} from '../services/roknApi';
import {
  openCoinCheckout,
  subscribeCoinCheckoutCredits,
} from '../services/coinCheckout';
import {
  asRecord,
  errorCode,
  errorPayload,
  learnerErrorMessage,
} from '../utils/errorPayload';
import {
  authorizeCourseCheckout,
  cancelCourseCheckout,
  getCourseCheckout,
  getLatestCourseCheckout,
  quoteCourseCheckout,
  resumeCourseCheckout,
  selectCheckoutPackage,
  type CourseCheckout,
  type CourseCheckoutMode,
  type CourseCheckoutFeature,
} from '../services/api/courseCheckout';
import type {CoinPackage} from '../services/api/coinPackageMapper';
import {courseCheckoutTransport} from '../services/checkoutRouting';
import {trackProductEvent} from '../services/productAnalytics';

type Params = {
  courseId: string;
  mode?: CourseCheckoutMode;
  planCode?: string;
  courseRevision?: number;
  requiredFeature?: CourseCheckoutFeature;
  visible: boolean;
  onCompleted: () => void | Promise<void>;
  onPaymentRecovery?: () => void;
};

/** One explicit authorization owns both the top-up and enrollment on the server.
 * Foreground/restart recovery only reads that intent; it never opens payment. */
export function useCourseSubscriptionCheckout({
  courseId,
  mode = 'purchase',
  planCode,
  courseRevision,
  requiredFeature,
  visible,
  onCompleted,
  onPaymentRecovery,
}: Params) {
  const [quote, setQuote] = useState<CourseCheckout | null>(null);
  const [coinPackage, setCoinPackage] = useState<CoinPackage>();
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const [blockingCheckout, setBlockingCheckout] = useState<{
    id: string;
    courseId: string;
  } | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const generation = useRef(0);
  const flight = useRef(false);
  const current = useRef({courseId, planCode, visible});
  const quoteRef = useRef(quote);
  const completedRef = useRef(new Set<string>());
  const viewedRef = useRef<string | null>(null);
  const callback = useRef(onCompleted);
  const recoveryCallback = useRef(onPaymentRecovery);
  current.current = {courseId, planCode, visible};
  quoteRef.current = quote;
  callback.current = onCompleted;
  recoveryCallback.current = onPaymentRecovery;
  const owns = useCallback(
    (token: number) =>
      generation.current === token &&
      current.current.visible &&
      current.current.courseId === courseId &&
      current.current.planCode === planCode,
    [courseId, planCode],
  );
  const acceptCompleted = useCallback(async (next: CourseCheckout) => {
    setQuote(next);
    if (next.status === 'completed' && !completedRef.current.has(next.id)) {
      completedRef.current.add(next.id);
      // The server records the receipt transition even when this sheet is
      // closed or the process is gone. This callback only updates learner UI.
      await callback.current();
    }
  }, []);

  useEffect(() => {
    const token = ++generation.current;
    setQuote(null);
    setCoinPackage(undefined);
    setNotice('');
    setBlockingCheckout(null);
    setBusy(false);
    flight.current = false;
    if (!visible || !planCode) {
      if (!visible) viewedRef.current = null;
      setLoading(false);
      return;
    }
    setLoading(true);
    void (async () => {
      try {
        const boundary = await captureAccountSessionBoundary();
        assertAccountSessionBoundary(boundary);
        if (!owns(token)) return;
        const viewKey = `${boundary.scope}:${courseId}`;
        if (mode === 'purchase' && viewedRef.current !== viewKey) {
          viewedRef.current = viewKey;
          void trackProductEvent({
            event_name: 'paywall_viewed',
            screen_key: 'course_details',
            course_id: courseId,
          });
        }
        let latest = await getLatestCourseCheckout(courseId);
        assertAccountSessionBoundary(boundary);
        if (!owns(token)) return;
        if (latest?.status === 'pending_payment') {
          // Own this evidence before resume can cancel/requote or throw; the
          // final UI state need not ever contain the original pending quote.
          recoveryCallback.current?.();
          latest = await resumeCourseCheckout(latest.id);
          assertAccountSessionBoundary(boundary);
          if (!owns(token)) return;
          if (latest.status === 'completed') {
            await acceptCompleted(latest);
            return;
          }
          if (latest.status === 'pending_payment') {
            setQuote(latest);
            if (
              courseCheckoutTransport.kind === 'external' &&
              latest.channel === 'direct'
            ) {
              setCoinPackage(
                latest.selectedPackage ||
                  latest.packages.find(
                    item => item.id === latest?.selectedPackageId,
                  ),
              );
            }
            setNotice('الدفع قيد التأكيد\nلا تدفع مرة أخرى');
            return;
          }
        }
        // A completed old purchase may precede a new upgrade. A fresh quote
        // remains authoritative for whether this requested target is available.
        const input = {courseId, planCode, mode, requiredFeature};
        let next = await quoteCourseCheckout(input);
        assertAccountSessionBoundary(boundary);
        if (!owns(token)) return;
        if (
          courseRevision !== undefined &&
          next.courseRevision !== courseRevision
        )
          throw new Error('COURSE_CHECKOUT_TERMS_CHANGED');
        if (next.deficit > 0) {
          const available =
            courseCheckoutTransport.kind === 'native'
              ? await getCoinPackages()
              : next.packages;
          assertAccountSessionBoundary(boundary);
          if (!owns(token)) return;
          const eligibleIds = new Set(next.packages.map(item => item.id));
          const eligiblePackages = available.filter(
            item =>
              eligibleIds.has(item.id) &&
              (courseCheckoutTransport.kind === 'external' ||
                Boolean(item.displayPrice)),
          );
          const chosen = selectCheckoutPackage(eligiblePackages, next.deficit);
          if (chosen) {
            next = await quoteCourseCheckout({...input, packageId: chosen.id});
            assertAccountSessionBoundary(boundary);
            if (!owns(token)) return;
            if (
              courseRevision !== undefined &&
              next.courseRevision !== courseRevision
            )
              throw new Error('COURSE_CHECKOUT_TERMS_CHANGED');
            if (
              next.selectedPackageId !== chosen.id ||
              chosen.coins < next.deficit ||
              (courseCheckoutTransport.kind === 'external' &&
                (!next.selectedPackage ||
                  next.selectedPackage.coins !== chosen.coins ||
                  next.selectedPackage.price !== chosen.price))
            )
              throw new Error('COURSE_CHECKOUT_PACKAGE_CHANGED');
            // Direct amounts belong to the bound server contract; native
            // display prices belong to the localized store product.
            setCoinPackage(
              courseCheckoutTransport.kind === 'external'
                ? next.selectedPackage
                : chosen,
            );
          } else {
            setNotice('الدفع غير متاح لهذا الاشتراك الآن');
          }
        }
        setQuote(next);
      } catch (error) {
        if (!owns(token)) return;
        setNotice(
          errorCode(error) === 'COURSE_CHECKOUT_TERMS_CHANGED'
            ? 'تغيّرت الاشتراكات\nأغلق النافذة وحدّث الكورس لعرض التفاصيل الجديدة'
            : learnerErrorMessage(error, 'تعذّر تجهيز الاشتراك\nحاول مرة أخرى'),
        );
      } finally {
        if (owns(token)) setLoading(false);
      }
    })();
    return () => {
      generation.current += 1;
    };
  }, [
    acceptCompleted,
    courseId,
    courseRevision,
    mode,
    owns,
    planCode,
    reloadKey,
    requiredFeature,
    visible,
  ]);

  // A pending checkout belongs to the account, not necessarily this course.
  // Resolve it separately so its receipt can never mark this course as bought.
  const resolveBlockingCheckout = useCallback(
    async (action: 'resume' | 'cancel') => {
      if (!blockingCheckout || flight.current) return;
      const token = generation.current;
      flight.current = true;
      setBusy(true);
      try {
        const boundary = await captureAccountSessionBoundary();
        const next = await (action === 'cancel'
          ? cancelCourseCheckout(blockingCheckout.id)
          : resumeCourseCheckout(blockingCheckout.id));
        assertAccountSessionBoundary(boundary);
        if (!owns(token)) return;
        if (
          next.id !== blockingCheckout.id ||
          next.courseId !== blockingCheckout.courseId
        )
          throw new Error('CHECKOUT_RECOVERY_MISMATCH');
        if (next.status === 'pending_payment') {
          setNotice(
            'الدفع السابق قيد التأكيد\nتحقق منه أو ألغِ طلبه قبل اشتراك جديد',
          );
          return;
        }
        setBlockingCheckout(null);
        if (next.courseId === courseId && next.status === 'completed') {
          await acceptCompleted(next);
        } else {
          // Requote the current course against the authoritative updated balance.
          setReloadKey(value => value + 1);
        }
      } catch {
        if (owns(token))
          setNotice('تعذّر تأكيد حالة الدفع السابق\nحاول مرة أخرى');
      } finally {
        if (owns(token)) {
          flight.current = false;
          setBusy(false);
        }
      }
    },
    [acceptCompleted, blockingCheckout, courseId, owns],
  );

  const refreshPending = useCallback(async () => {
    if (blockingCheckout) {
      await resolveBlockingCheckout('resume');
      return;
    }
    const previous = quoteRef.current;
    if (!previous || previous.status !== 'pending_payment' || flight.current)
      return;
    const token = generation.current;
    flight.current = true;
    setBusy(true);
    try {
      const next = await resumeCourseCheckout(previous.id);
      if (!owns(token)) return;
      await acceptCompleted(next);
      if (next.status === 'reconfirm_required')
        setNotice(
          'تغيّرت تفاصيل الاشتراك\nرصيد الشحن محفوظ ويمكنك مراجعة الاختيار',
        );
      else if (next.status === 'pending_payment')
        setNotice('الدفع قيد التأكيد\nلا تدفع مرة أخرى');
    } catch {
      if (owns(token))
        setNotice('تعذّر تأكيد النتيجة\nلا تشحن مرة أخرى قبل التحقق');
    } finally {
      if (owns(token)) {
        flight.current = false;
        setBusy(false);
      }
    }
  }, [acceptCompleted, blockingCheckout, owns, resolveBlockingCheckout]);

  useEffect(() => {
    if (!visible) return;
    const app = AppState.addEventListener('change', state => {
      if (state === 'active') void refreshPending();
    });
    const credits = subscribeCoinCheckoutCredits(() => {
      void refreshPending();
    });
    return () => {
      app.remove();
      credits();
    };
  }, [refreshPending, visible]);

  const confirm = useCallback(async () => {
    if (!quote || loading || flight.current) return;
    if (blockingCheckout) {
      await resolveBlockingCheckout('resume');
      return;
    }
    if (
      quote.status === 'pending_payment' &&
      !(
        courseCheckoutTransport.kind === 'external' &&
        quote.canResumePayment &&
        coinPackage
      )
    ) {
      await refreshPending();
      return;
    }
    if (quote.status !== 'quoted' && quote.status !== 'pending_payment') {
      setReloadKey(value => value + 1);
      return;
    }
    if (quote.deficit > 0 && !coinPackage) return;
    if (
      quote.status === 'quoted' &&
      Date.parse(quote.expiresAt) <= Date.now()
    ) {
      setReloadKey(value => value + 1);
      return;
    }
    const token = generation.current;
    flight.current = true;
    setBusy(true);
    setNotice('');
    let authorized = false;
    try {
      const boundary = await captureAccountSessionBoundary();
      assertAccountSessionBoundary(boundary);
      if (!owns(token)) return;
      let next = await authorizeCourseCheckout(quote.id);
      assertAccountSessionBoundary(boundary);
      if (!owns(token)) return;
      authorized = next.status === 'pending_payment';
      setQuote(next);
      if (next.status === 'completed') {
        await acceptCompleted(next);
        return;
      }
      if (next.status !== 'pending_payment' || !coinPackage) {
        setNotice('تغيّرت تفاصيل الاشتراك\nراجعها قبل المتابعة');
        return;
      }
      const payment = await openCoinCheckout(coinPackage, {
        transport: courseCheckoutTransport,
        courseCheckoutId: next.id,
        returnTo: {
          name: 'CourseDetails',
          params: {
            courseId,
            ...(mode === 'upgrade'
              ? {openFullTrackUpgrade: true}
              : {openPurchase: true, purchasePlanCode: planCode}),
          },
        },
      });
      assertAccountSessionBoundary(boundary);
      if (!owns(token)) return;
      if (payment.cancelled) {
        await cancelCourseCheckout(next.id);
        assertAccountSessionBoundary(boundary);
        if (!owns(token)) return;
        setReloadKey(value => value + 1);
        return;
      }
      next = await resumeCourseCheckout(next.id);
      assertAccountSessionBoundary(boundary);
      if (!owns(token)) return;
      await acceptCompleted(next);
      if (next.status === 'pending_payment')
        setNotice('الدفع قيد التأكيد\nسيُفعّل الاشتراك بعد التأكيد');
      else if (next.status !== 'completed')
        setNotice('تم تحديث الرصيد\nراجع تفاصيل الاشتراك قبل المتابعة');
    } catch (error) {
      if (!owns(token)) return;
      if (errorCode(error) === 'checkout_already_pending') {
        const active = asRecord(
          asRecord(errorPayload(error).data)?.active_checkout,
        );
        const id = typeof active?.id === 'string' ? active.id : '';
        const activeCourseId = Number(active?.course_id);
        if (
          /^[a-zA-Z0-9-]{1,100}$/.test(id) &&
          Number.isSafeInteger(activeCourseId) &&
          activeCourseId > 0
        ) {
          setBlockingCheckout({id, courseId: String(activeCourseId)});
          setNotice(
            'عندك طلب اشتراك سابق لم يُحسم\nتحقق من الدفع أو ألغِ الطلب السابق',
          );
          return;
        }
      }
      // A lost authorization response can still represent a committed debit.
      // Read its durable result before allowing another payment attempt.
      try {
        const next = await getCourseCheckout(quote.id);
        if (!owns(token)) return;
        await acceptCompleted(next);
        if (next.status === 'completed') return;
        authorized = next.status === 'pending_payment';
      } catch {}
      if (!owns(token)) return;
      setNotice(
        authorized
          ? 'تعذّر تأكيد النتيجة\nتحقق من الدفع قبل محاولة جديدة'
          : learnerErrorMessage(
              error,
              errorCode(error).includes('changed')
                ? 'تغيّرت التفاصيل\nراجع الاشتراك من جديد'
                : 'لم تكتمل العملية\nحاول مرة أخرى',
            ),
      );
    } finally {
      if (owns(token)) {
        flight.current = false;
        setBusy(false);
      }
    }
  }, [
    acceptCompleted,
    blockingCheckout,
    coinPackage,
    courseId,
    loading,
    mode,
    owns,
    planCode,
    quote,
    refreshPending,
    resolveBlockingCheckout,
  ]);

  const retry = useCallback(() => {
    if (!flight.current) setReloadKey(value => value + 1);
  }, []);
  const cancelPending = useCallback(async () => {
    if (blockingCheckout) {
      await resolveBlockingCheckout('cancel');
      return;
    }
    const previous = quoteRef.current;
    if (!previous || previous.status !== 'pending_payment' || flight.current)
      return;
    const token = generation.current;
    flight.current = true;
    setBusy(true);
    try {
      const next = await cancelCourseCheckout(previous.id);
      if (!owns(token)) return;
      if (next.status === 'completed') {
        await acceptCompleted(next);
        return;
      }
      setQuote(next);
      setReloadKey(value => value + 1);
    } catch {
      if (owns(token))
        setNotice('تعذّر إلغاء الطلب\nتحقق من حالته قبل محاولة جديدة');
    } finally {
      if (owns(token)) {
        flight.current = false;
        setBusy(false);
      }
    }
  }, [acceptCompleted, blockingCheckout, owns, resolveBlockingCheckout]);
  return {
    quote,
    coinPackage,
    loading,
    busy,
    notice,
    confirm,
    cancelPending,
    retry,
    blockedByPreviousCheckout: Boolean(blockingCheckout),
    pending: quote?.status === 'pending_payment',
    canResumePayment: Boolean(
      courseCheckoutTransport.kind === 'external' &&
        quote?.channel === 'direct' &&
        quote.status === 'pending_payment' &&
        quote.canResumePayment &&
        coinPackage,
    ),
  };
}
