import {useQuery, useQueryClient} from '@tanstack/react-query';
import {useCallback, useEffect, useMemo, useState} from 'react';
import type {Dispatch, SetStateAction} from 'react';
import type {CoinPackage} from '../../../services/api/coinPackageMapper';
import {isCourseRevisionChangedError} from '../../../services/api/courseReadFailures';
import {
  getCourseDetailsSnapshot,
  hasSession,
  isCourseUnavailableError,
} from '../../../services/roknApi';
import type {CourseDetails as CourseDetailsDto} from '../../../services/roknApi';
import {friendlyNetworkMessage} from '../../../services/networkExperience';
import {subscribeWalletSettlements} from '../../../services/walletSettlement';
import {assertAccountSessionBoundary} from '../../../constants/helpers';
import {
  applyLocalLearningState,
  mapCoursePayload,
} from '../../../components/VideoPlayer/courseLearningApi';
import type {CourseLearningData} from '../../../components/VideoPlayer/types';
import {
  learningNavigationHandoff,
  type FreshLearningRead,
} from '../../../components/VideoPlayer/courseLearning/navigationHandoff';
import {
  captureScopedBoundary,
  useAccountQueryScope,
} from '../../../services/query/accountQueryScope';
import {isRestoredSnapshot} from '../../../services/query/queryClient';
import {accountQueryKeys} from '../../../services/query/queryKeys';
import {
  discardSnapshotWith,
  restoreBeforeFailing,
} from '../../../services/query/queryPersistence';
import {walletQueryOptions} from '../../../services/query/walletQueries';
import {settleWithin} from '../../../utils/settleWithin';
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

/** One course-details query entry: the details and their learning graph. */
export type CourseDetailsQueryData = {
  course: CourseDetailsDto;
  learning: CourseLearningData | null;
  /** Only a fresh server response may prepare the learning transition. */
  learningRead: FreshLearningRead | null;
};

export type CourseWalletUpdate = Omit<
  CourseWalletState,
  'rewardContributionCap'
> & {
  rewardContributionCap?: number;
};

const CACHED_DETAILS_NOTICE =
  'نعرض آخر تفاصيل محفوظة\nسنحدّثها عند عودة الاتصال';
const UNAVAILABLE_ERROR =
  'هذا الكورس غير متاح الآن\nعد إلى الرئيسية واختر كورسًا آخر';
const WALLET_NOTICE = 'تعذّر تحديث بعض بيانات المحفظة\nحدّث الصفحة لعرض أحدثها';

const isAccountChange = (error: unknown) =>
  error instanceof Error && error.message === 'ACCOUNT_CHANGED_DURING_REQUEST';

/**
 * A foreground read can predate an acknowledged rating edit or deletion. Its
 * personal version is monotonic, including deletes. Equal versions still
 * accept aggregates changed by other students. Used as the query's
 * `structuralSharing`, so it also guards reads racing `setQueryData`.
 */
export const keepNewerRating = (previous: unknown, next: unknown): unknown => {
  const current = previous as CourseDetailsQueryData | undefined;
  const incoming = next as CourseDetailsQueryData;
  if (
    !current ||
    !incoming?.course ||
    (current.course.ratingVersion ?? 0) <= (incoming.course.ratingVersion ?? 0)
  ) {
    return incoming;
  }
  return {
    ...incoming,
    course: {
      ...incoming.course,
      userRating: current.course.userRating,
      ratingVersion: current.course.ratingVersion,
      ratingAverage: current.course.ratingAverage,
      ratingsCount: current.course.ratingsCount,
    },
  };
};

export const useCourseDetailsData = ({
  courseId,
  identityKey,
}: UseCourseDetailsDataParams) => {
  const client = useQueryClient();
  const {scope, failed: scopeFailed} = useAccountQueryScope(identityKey);
  const keys = useMemo(() => accountQueryKeys(scope), [scope]);
  const detailsKey = useMemo(
    () => keys.courseDetails(courseId),
    [courseId, keys],
  );
  const walletKey = useMemo(() => keys.wallet(), [keys]);
  const [remotePackages, setRemotePackages] = useState<CoinPackage[]>([]);
  const [walletOverride, setWalletOverride] = useState<{
    wallet: CourseWalletState;
    at: number;
  } | null>(null);
  const [ownershipReloading, setOwnershipReloading] = useState(false);

  const details = useQuery({
    queryKey: detailsKey,
    queryFn: restoreBeforeFailing(
      async ({signal}): Promise<CourseDetailsQueryData> => {
        const boundary = await captureScopedBoundary(scope!);
        try {
          const snapshot = await getCourseDetailsSnapshot(courseId, {signal});
          const course = snapshot.course;
          const receivedAt = Date.now();
          const mappedLearningCourse = snapshot.responsePayload
            ? mapCoursePayload(snapshot.responsePayload)
            : null;
          if (course.owned && !mappedLearningCourse) {
            throw new Error('API_CONTRACT_INVALID_COURSE_LEARNING_SNAPSHOT');
          }
          // Local completion hints are optional; a stalled native cache must
          // not hide the fresh server course or replace its access gates.
          const learning =
            course.owned && mappedLearningCourse
              ? await settleWithin(
                  applyLocalLearningState(mappedLearningCourse),
                  mappedLearningCourse,
                )
              : null;
          assertAccountSessionBoundary(boundary);
          return {
            course,
            learning,
            learningRead:
              !course.fromCache && mappedLearningCourse
                ? {course: mappedLearningCourse, boundary, receivedAt}
                : null,
          };
        } catch (error) {
          // A retired course or a changed published revision must not survive
          // as the guest's device copy.
          if (
            isCourseUnavailableError(error) ||
            isCourseRevisionChangedError(error)
          ) {
            throw discardSnapshotWith(error);
          }
          throw error;
        }
      },
    ),
    enabled: scope !== null && Boolean(courseId),
    structuralSharing: keepNewerRating,
    // The screen reloads on focus and on return to the app itself.
    refetchOnWindowFocus: false,
  });

  const detailsQuery = client
    .getQueryCache()
    .find({queryKey: detailsKey, exact: true});
  const detailsError = details.isError ? details.error : null;
  const unavailable = Boolean(
    detailsError && isCourseUnavailableError(detailsError),
  );
  // Details are network-first: a guest's saved copy is shown only after the
  // server read fails, never while it is still loading.
  const awaitingServer = isRestoredSnapshot(detailsQuery) && details.isFetching;
  const shownData =
    details.data && !unavailable && !awaitingServer ? details.data : null;
  const guestScope = Boolean(scope?.startsWith('guest-'));
  const savedCopyShown = Boolean(
    shownData &&
      (shownData.course.fromCache ||
        (guestScope && detailsError && !isAccountChange(detailsError))),
  );
  const remoteCourse = shownData
    ? savedCopyShown && !shownData.course.fromCache
      ? {...shownData.course, fromCache: true}
      : shownData.course
    : null;
  const detailsLoaded = remoteCourse !== null;

  const session = useQuery({
    queryKey: keys.session(),
    queryFn: () => hasSession().catch(() => false),
    enabled: scope !== null,
    staleTime: 0,
  });

  const needsCommerce = Boolean(
    detailsLoaded &&
      session.data === true &&
      remoteCourse &&
      !remoteCourse.owned &&
      courseRequiresWallet(remoteCourse),
  );
  // An optional display hint. SubscriptionSheet owns the binding quote and
  // loads only its actual payment provider when needed.
  const wallet = useQuery({
    ...walletQueryOptions(scope),
    enabled: needsCommerce,
    // Every details load reads the balance again, as before.
    refetchOnMount: true,
  });
  // An acknowledged balance wins over any read that started before it.
  const remoteWallet: CourseWalletState | null =
    walletOverride &&
    (!wallet.data || wallet.dataUpdatedAt <= walletOverride.at)
      ? walletOverride.wallet
      : wallet.data ?? null;

  const reloadRemote = useCallback(() => {
    if (!scope || !courseId) return;
    client.setQueryData<CourseDetailsQueryData>(
      detailsKey,
      data => data && {...data, learningRead: null},
    );
    void client.refetchQueries({queryKey: detailsKey, exact: true});
    if (needsCommerce) {
      void client.refetchQueries({queryKey: walletKey, exact: true});
    }
  }, [client, courseId, detailsKey, needsCommerce, scope, walletKey]);

  const prepareLearningNavigation = useCallback(() => {
    // Read the entry itself: an ownership change or reload in this same tick
    // has already retired the prepared read.
    const read =
      client.getQueryData<CourseDetailsQueryData>(detailsKey)?.learningRead;
    if (!read || read.course.id !== courseId || savedCopyShown)
      return undefined;
    return learningNavigationHandoff.prepare(read);
  }, [client, courseId, detailsKey, savedCopyShown]);

  // Kashier credits, store credits, rewards and course debits all arrive on
  // the one wallet settlement channel.
  useEffect(
    () =>
      subscribeWalletSettlements(boundary => {
        assertAccountSessionBoundary(boundary);
        reloadRemote();
      }),
    [reloadRemote],
  );

  // A new owner or course starts without the previous one's local overlays.
  useEffect(() => {
    setWalletOverride(null);
    setRemotePackages([]);
    setOwnershipReloading(false);
  }, [courseId, scope]);

  const setRemoteCourse: Dispatch<SetStateAction<CourseDetailsDto | null>> =
    useCallback(
      update => {
        client.setQueryData<CourseDetailsQueryData>(detailsKey, data => {
          const next =
            typeof update === 'function'
              ? update(data?.course ?? null)
              : update;
          return next
            ? {
                course: next,
                learning: data?.learning ?? null,
                learningRead: null,
              }
            : data;
        });
      },
      [client, detailsKey],
    );

  // TanStack "Optimistic Updates": cancel the read in flight so a response
  // that predates the purchase cannot relock the course, apply the change,
  // then let the next read be authoritative again (a refund still relocks).
  const setRemoteOwned = useCallback(
    (owned: boolean) => {
      const current = client.getQueryData<CourseDetailsQueryData>(detailsKey);
      if (!current) return;
      const ownershipChanged = current.course.owned !== owned;
      void client.cancelQueries({queryKey: detailsKey, exact: true});
      client.setQueryData<CourseDetailsQueryData>(detailsKey, {
        course: {...current.course, owned},
        learning: ownershipChanged ? null : current.learning,
        learningRead: null,
      });
      if (ownershipChanged) {
        setOwnershipReloading(true);
        void client
          .refetchQueries({queryKey: detailsKey, exact: true})
          .finally(() => setOwnershipReloading(false));
      }
    },
    [client, detailsKey],
  );

  const updateRemoteWallet = useCallback(
    (next: CourseWalletUpdate) => {
      void client.cancelQueries({queryKey: walletKey, exact: true});
      setWalletOverride(current => ({
        at: Date.now(),
        wallet: {
          balance: next.balance,
          paidBalance: next.paidBalance,
          rewardBalance: next.rewardBalance,
          rewardContributionCap:
            next.rewardContributionCap ??
            current?.wallet.rewardContributionCap ??
            wallet.data?.rewardContributionCap ??
            0,
          spendableBalance: next.spendableBalance,
        },
      }));
    },
    [client, wallet.data?.rewardContributionCap, walletKey],
  );

  let remoteError = '';
  let remoteNotice = '';
  if (!courseId) {
    remoteError = 'عد إلى الرئيسية\nوافتح الكورس من هناك';
  } else if (scopeFailed) {
    remoteError = 'تعذّر تجهيز تفاصيل الكورس\nحاول مرة أخرى';
  } else if (detailsError && !details.isFetching) {
    if (isAccountChange(detailsError)) {
      remoteError = 'تغيّر الحساب\nحاول مرة أخرى';
    } else if (unavailable) {
      remoteError = UNAVAILABLE_ERROR;
    } else if (!detailsLoaded) {
      remoteError = friendlyNetworkMessage(detailsError, 'تفاصيل الكورس');
    } else if (!savedCopyShown) {
      remoteNotice = friendlyNetworkMessage(detailsError, 'تفاصيل الكورس');
    }
  }
  if (!remoteNotice && savedCopyShown && remoteCourse?.fromCache) {
    remoteNotice = CACHED_DETAILS_NOTICE;
  }
  if (
    !remoteNotice &&
    needsCommerce &&
    wallet.isError &&
    !wallet.isFetching &&
    remoteWallet === null
  ) {
    remoteNotice = WALLET_NOTICE;
  }

  const settled = Boolean(remoteError) || detailsLoaded;
  const ownerMatches = scope !== null || scopeFailed || !courseId;
  const remoteLoading =
    !settled ||
    ownershipReloading ||
    (!remoteError && !detailsLoaded && details.isFetching);

  return {
    course: {
      error: ownerMatches ? remoteError : '',
      loading: remoteLoading || !ownerMatches,
      notice: ownerMatches ? remoteNotice : '',
      reload: reloadRemote,
      prepareLearningNavigation,
      session: detailsLoaded ? session.data ?? null : null,
      setOwned: setRemoteOwned,
      setValue: setRemoteCourse,
      value: remoteCourse,
      learningValue: shownData?.learning ?? null,
    },
    commerce: {
      balance: remoteWallet?.balance ?? null,
      loading: needsCommerce && wallet.isFetching,
      packages: remotePackages,
      paidBalance: remoteWallet?.paidBalance ?? null,
      rewardBalance: remoteWallet?.rewardBalance ?? null,
      rewardContributionCap: remoteWallet?.rewardContributionCap ?? null,
      setPackages: setRemotePackages,
      spendableBalance: remoteWallet?.spendableBalance ?? null,
      updateWallet: updateRemoteWallet,
    },
  };
};

export type CourseDetailsData = ReturnType<typeof useCourseDetailsData>;
