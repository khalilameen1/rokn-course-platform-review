import {useCallback, useEffect, useRef, useState} from 'react';
import type {Dispatch, SetStateAction} from 'react';
import type {CoinPackage} from '../../../services/api/coinPackageMapper';
import {
  getCoinPackages,
  getCourseDetailsSnapshot,
  getWallet,
  hasSession,
  isCourseUnavailableError,
} from '../../../services/roknApi';
import type {CourseDetails as CourseDetailsDto} from '../../../services/roknApi';
import {
  friendlyNetworkMessage,
  networkFailureKind,
} from '../../../services/networkExperience';
import {CAN_START_NATIVE_CHECKOUT} from '../../../constants/distribution';
import {subscribeCoinCheckoutCredits} from '../../../services/coinCheckout';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../../constants/helpers';
import {
  applyLocalLearningState,
  mapCoursePayload,
} from '../../../components/VideoPlayer/courseLearningApi';
import type {CourseLearningData} from '../../../components/VideoPlayer/types';
import {courseRequiresWallet} from './purchaseTerms';

type UseCourseDetailsDataParams = {
  courseId: string;
  identityKey: string;
};

type CourseWalletState = {
  balance: number;
  paidBalance: number;
  rewardBalance: number;
  rewardContributionCap: number;
  spendableBalance: number;
};

type RemoteCourseSnapshot = {
  details: CourseDetailsDto;
  learning: CourseLearningData | null;
};

export type CourseWalletUpdate = Omit<
  CourseWalletState,
  'rewardContributionCap'
> & {
  rewardContributionCap?: number;
};

export const useCourseDetailsData = ({
  courseId,
  identityKey,
}: UseCourseDetailsDataParams) => {
  const [remoteWallet, setRemoteWallet] = useState<CourseWalletState | null>(
    null,
  );
  const [remotePackages, setRemotePackages] = useState<CoinPackage[]>([]);
  const [remoteSession, setRemoteSession] = useState<boolean | null>(null);
  const [remoteSnapshot, setRemoteSnapshot] =
    useState<RemoteCourseSnapshot | null>(null);
  const remoteCourse = remoteSnapshot?.details ?? null;
  const remoteLearningCourse = remoteSnapshot?.learning ?? null;
  const [remoteCommerceLoading, setRemoteCommerceLoading] = useState(false);
  const loadedCourseRef = useRef<CourseDetailsDto | null>(null);
  const ownershipWriteEpochRef = useRef(0);
  const loadedOwnerRef = useRef(identityKey);
  const displayScopeRef = useRef({courseId, identityKey});
  const [remoteLoading, setRemoteLoading] = useState(true);
  const [remoteError, setRemoteError] = useState('');
  const [remoteNotice, setRemoteNotice] = useState('');
  const [remoteReload, setRemoteReload] = useState(0);
  const reloadRemote = useCallback(
    () => setRemoteReload(value => value + 1),
    [],
  );

  useEffect(() => {
    let active = true;
    const controller = new AbortController();
    if (
      displayScopeRef.current.courseId !== courseId ||
      displayScopeRef.current.identityKey !== identityKey
    ) {
      displayScopeRef.current = {courseId, identityKey};
      setRemoteSnapshot(null);
      setRemoteWallet(null);
      setRemotePackages([]);
      setRemoteCommerceLoading(false);
      setRemoteSession(null);
      ownershipWriteEpochRef.current += 1;
      setRemoteError('');
      setRemoteNotice('');
      setRemoteLoading(true);
    }
    if (!courseId) {
      setRemoteLoading(false);
      setRemoteError('عد إلى الرئيسية\nوافتح الكورس من هناك');
      return () => {
        active = false;
        controller.abort();
      };
    }
    void (async () => {
      const boundary = await captureAccountSessionBoundary().catch(() => null);
      if (!boundary) {
        if (active) {
          setRemoteLoading(false);
          setRemoteError('تعذّر تجهيز تفاصيل الكورس\nحاول مرة أخرى');
        }
        return;
      }
      if (!active) return;
      const stillOwned = () => {
        if (!active) return false;
        try {
          assertAccountSessionBoundary(boundary);
          return true;
        } catch {
          setRemoteLoading(false);
          setRemoteError('تغيّر الحساب\nحاول مرة أخرى');
          return false;
        }
      };
      const hasCurrentDetails =
        loadedCourseRef.current?.id === courseId &&
        loadedOwnerRef.current === identityKey;
      setRemoteLoading(!hasCurrentDetails);
      setRemoteError('');
      setRemoteNotice('');
      if (
        loadedCourseRef.current?.id !== courseId ||
        loadedOwnerRef.current !== identityKey
      ) {
        loadedCourseRef.current = null;
        loadedOwnerRef.current = identityKey;
        setRemoteSnapshot(null);
        setRemoteWallet(null);
        setRemotePackages([]);
        setRemoteCommerceLoading(false);
      }
      // The public definition is independent from keychain/session and wallet
      // reads. Start both clocks together so a guest can see the course even
      // if secure storage is slow or locked.
      const sessionFlight = hasSession().catch(() => false);
      let detailsLoaded =
        loadedCourseRef.current?.id === courseId &&
        loadedOwnerRef.current === identityKey;
      let resolvedDetails = detailsLoaded ? loadedCourseRef.current : null;
      const ownershipWriteEpoch = ownershipWriteEpochRef.current;
      try {
        const snapshot = await getCourseDetailsSnapshot(courseId, {
          signal: controller.signal,
        });
        const details = snapshot.course;
        const mappedLearningCourse = details.owned && snapshot.responsePayload
          ? mapCoursePayload(snapshot.responsePayload)
          : null;
        if (details.owned && !mappedLearningCourse) {
          throw new Error('API_CONTRACT_INVALID_COURSE_LEARNING_SNAPSHOT');
        }
        const learningCourse = mappedLearningCourse
          ? await applyLocalLearningState(mappedLearningCourse)
          : null;
        assertAccountSessionBoundary(boundary);
        detailsLoaded = true;
        resolvedDetails = details;
        if (stillOwned()) {
          const ownershipChangedWhileReading =
            ownershipWriteEpochRef.current !== ownershipWriteEpoch;
          loadedOwnerRef.current = identityKey;
          setRemoteSnapshot(current => {
            const next = {
              ...details,
              // Preserve a purchase that completed while this particular read
              // was in flight. Every later read is authoritative again, so a
              // refunded, held, or revoked enrollment cannot stay unlocked
              // merely because this screen once observed `owned: true`.
              owned: ownershipChangedWhileReading
                ? current?.details.owned ?? details.owned
                : details.owned,
            };
            loadedCourseRef.current = next;
            return {
              details: next,
              learning: ownershipChangedWhileReading
                ? current?.learning ?? null
                : learningCourse,
            };
          });
          if (details.fromCache) {
            setRemoteNotice(
              'نعرض آخر تفاصيل محفوظة\nسنحدّثها عند عودة الاتصال',
            );
          }
        }
      } catch (error) {
        if (networkFailureKind(error) === 'cancelled') return;
        if (stillOwned()) {
          if (isCourseUnavailableError(error)) {
            loadedCourseRef.current = null;
            setRemoteSnapshot(null);
            detailsLoaded = false;
            setRemoteError(
              'هذا الكورس غير متاح الآن\nعد إلى الرئيسية واختر كورسًا آخر',
            );
          }
          if (!isCourseUnavailableError(error)) {
            const message = friendlyNetworkMessage(error, 'تفاصيل الكورس');
            if (detailsLoaded) setRemoteNotice(message);
            else setRemoteError(message);
          }
        }
      }
      if (!detailsLoaded) {
        if (stillOwned()) setRemoteLoading(false);
        return;
      }
      if (stillOwned()) setRemoteLoading(false);
      const sessionAvailable = await sessionFlight;
      if (!stillOwned()) return;
      setRemoteSession(sessionAvailable);
      const needsCommerce = Boolean(
        sessionAvailable &&
          resolvedDetails &&
          !resolvedDetails.owned &&
          courseRequiresWallet(resolvedDetails),
      );
      if (needsCommerce) {
        setRemoteCommerceLoading(true);
        const [walletResult, packagesResult] = await Promise.allSettled([
          getWallet(),
          getCoinPackages(),
        ]);
        if (!stillOwned()) return;
        if (walletResult.status === 'fulfilled') {
          setRemoteWallet(walletResult.value);
        }
        if (packagesResult.status === 'fulfilled') {
          setRemotePackages(packagesResult.value);
        }
        if (
          walletResult.status === 'rejected' ||
          packagesResult.status === 'rejected'
        ) {
          setRemoteNotice(
            'تعذّر تحديث بعض بيانات المحفظة\nحدّث الصفحة لعرض أحدثها',
          );
        }
      }
      if (stillOwned()) setRemoteCommerceLoading(false);
    })();
    return () => {
      active = false;
      controller.abort();
    };
  }, [courseId, identityKey, remoteReload]);

  useEffect(() => {
    let active = true;
    let unsubscribe: () => void = () => undefined;
    const reloadAfterCredit = () => setRemoteReload(value => value + 1);
    const unsubscribeExternal = subscribeCoinCheckoutCredits(reloadAfterCredit);
    if (CAN_START_NATIVE_CHECKOUT) {
      void import('../../../services/nativeStoreBilling').then(storeBilling => {
        if (!active) return;
        unsubscribe =
          storeBilling.subscribeNativeStoreCredits(reloadAfterCredit);
      });
    }
    return () => {
      active = false;
      unsubscribe();
      unsubscribeExternal();
    };
  }, []);

  const ownerMatches =
    displayScopeRef.current.identityKey === identityKey &&
    displayScopeRef.current.courseId === courseId;

  const setRemoteCourse: Dispatch<SetStateAction<CourseDetailsDto | null>> =
    useCallback(update => {
      setRemoteSnapshot(current => {
        const currentDetails = current?.details ?? null;
        const next =
          typeof update === 'function'
            ? update(currentDetails)
            : update;
        loadedCourseRef.current = next;
        return next
          ? {details: next, learning: current?.learning ?? null}
          : null;
      });
    }, []);

  const setRemoteOwned = useCallback(
    (owned: boolean) => {
      const ownershipChanged = loadedCourseRef.current?.owned !== owned;
      ownershipWriteEpochRef.current += 1;
      setRemoteSnapshot(current => {
        if (!current) return current;
        const next = {...current.details, owned};
        loadedCourseRef.current = next;
        return {
          details: next,
          learning: ownershipChanged ? null : current.learning,
        };
      });
      if (ownershipChanged) {
        setRemoteLoading(true);
        reloadRemote();
      }
    },
    [reloadRemote],
  );

  const updateRemoteWallet = useCallback((next: CourseWalletUpdate) => {
    setRemoteWallet(current => ({
      balance: next.balance,
      paidBalance: next.paidBalance,
      rewardBalance: next.rewardBalance,
      rewardContributionCap:
        next.rewardContributionCap ?? current?.rewardContributionCap ?? 0,
      spendableBalance: next.spendableBalance,
    }));
  }, []);

  return {
    course: {
      error: ownerMatches ? remoteError : '',
      loading: remoteLoading || !ownerMatches,
      notice: ownerMatches ? remoteNotice : '',
      reload: reloadRemote,
      session: ownerMatches ? remoteSession : null,
      setOwned: setRemoteOwned,
      setValue: setRemoteCourse,
      value: ownerMatches ? remoteCourse : null,
      learningValue: ownerMatches ? remoteLearningCourse : null,
    },
    commerce: {
      balance: ownerMatches ? remoteWallet?.balance ?? null : null,
      loading: ownerMatches ? remoteCommerceLoading : false,
      packages: ownerMatches ? remotePackages : [],
      paidBalance: ownerMatches ? remoteWallet?.paidBalance ?? null : null,
      rewardBalance: ownerMatches ? remoteWallet?.rewardBalance ?? null : null,
      rewardContributionCap: ownerMatches
        ? remoteWallet?.rewardContributionCap ?? null
        : null,
      setPackages: setRemotePackages,
      spendableBalance: ownerMatches
        ? remoteWallet?.spendableBalance ?? null
        : null,
      updateWallet: updateRemoteWallet,
    },
  };
};

export type CourseDetailsData = ReturnType<typeof useCourseDetailsData>;
