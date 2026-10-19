import {useFocusEffect} from '@react-navigation/native';
import {useQuery, useQueryClient} from '@tanstack/react-query';
import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {Alert} from 'react-native';
import {useSelector} from 'react-redux';
import {
  extractApiToken,
  extractUserProfile,
  sessionIdentityKey,
} from '../../constants/helpers';
import {learnerCopy} from '../../constants/learnerCopy';
import {useAccountQueryScope} from '../../services/query/accountQueryScope';
import {
  accountProfileQueryOptions,
  portfolioProfileQueryOptions,
} from '../../services/query/profileQueries';
import {accountQueryKeys} from '../../services/query/queryKeys';
import {trustedPortfolioShareUrl} from '../../services/publicLinks';
import {hasSession, type PortfolioProfile} from '../../services/roknApi';
import {shareOnce} from '../../services/systemActions';
import type {RootState} from '../../store/store';
import {portfolioSharingNotice} from './portfolioState';

const PARTIAL_READ_ERROR = 'تعذّر تحديث بعض بيانات الحساب';
const SHARE_STATUS_ERROR = 'تعذّر تحديث حالة المشاركة';

const isAccountChange = (error: unknown) =>
  error instanceof Error && error.message === 'ACCOUNT_CHANGED_DURING_REQUEST';

/**
 * The أنا tab header: identity and reviewed sharing status as two independent
 * account-scoped queries (ARCHITECTURE.md M1/M6). Both read on mount and on
 * every focus; a retry retires whatever is still in flight. Gallery changes
 * reset the sharing read (an old approval never outlives an edit), a saved
 * portfolio profile replaces it, and the share action re-reads it before
 * opening the system sheet because moderation can change while mounted.
 */
export function useProfileOverview() {
  const client = useQueryClient();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const user = extractUserProfile(storedUser);
  const hasStoredToken = Boolean(extractApiToken(storedUser));
  const identityKey = sessionIdentityKey(storedUser);
  const {scope, failed: scopeFailed, retry: retryScope} =
    useAccountQueryScope(identityKey);
  const scopeRef = useRef(scope);
  scopeRef.current = scope;
  const keys = useMemo(() => accountQueryKeys(scope), [scope]);
  const mountedRef = useRef(true);
  const shareCheckRef = useRef<symbol | null>(null);
  const hasShareablePortfolioRef = useRef(false);
  const [hasShareablePortfolio, setHasShareablePortfolio] = useState(false);
  // A failed share check withdraws the status it could not confirm.
  const [shareStatusError, setShareStatusError] = useState('');

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  useEffect(() => {
    setHasShareablePortfolio(false);
    hasShareablePortfolioRef.current = false;
    setShareStatusError('');
    shareCheckRef.current = null;
  }, [identityKey]);

  const session = useQuery({
    queryKey: keys.session(),
    queryFn: () => hasSession(),
    enabled: scope !== null,
    staleTime: 0,
    refetchOnWindowFocus: false,
  });
  const signedIn = session.data === true;
  const enabled = scope !== null && signedIn;
  const identity = useQuery({...accountProfileQueryOptions(scope), enabled});
  const sharing = useQuery({...portfolioProfileQueryOptions(scope), enabled});

  // A later successful status read (focus, retry, a saved profile) clears
  // the share check's own failure.
  const sharingUpdatedAt = sharing.dataUpdatedAt;
  useEffect(() => {
    if (sharingUpdatedAt > 0) setShareStatusError('');
  }, [sharingUpdatedAt]);

  // TanStack React Native guide, "Refresh on Screen focus": the first focus
  // is the mount read; every later focus (and a retry) reads both again,
  // retiring anything still in flight, a first read included (`refetch`
  // alone would join a first read instead of restarting it).
  const reloadRef = useRef<() => void>(() => undefined);
  reloadRef.current = () => {
    const owner = scope;
    if (owner === null) return;
    void session.refetch({cancelRefetch: false}).then(async result => {
      if (result.data !== true || scopeRef.current !== owner) return;
      const ownedKeys = accountQueryKeys(owner);
      await Promise.all([
        client.cancelQueries({queryKey: ownedKeys.accountProfile(), exact: true}),
        client.cancelQueries({
          queryKey: ownedKeys.portfolioProfile(),
          exact: true,
        }),
      ]);
      if (scopeRef.current !== owner) return;
      void identity.refetch();
      void sharing.refetch();
    });
  };
  const focusedBeforeRef = useRef(false);
  useFocusEffect(
    useCallback(() => {
      if (focusedBeforeRef.current) reloadRef.current();
      focusedBeforeRef.current = true;
    }, []),
  );

  const retry = useCallback(() => {
    if (scopeFailed) retryScope();
    reloadRef.current();
  }, [retryScope, scopeFailed]);

  const serverSession = scope === null ? null : session.data ?? null;
  const authenticatedIdentity = hasStoredToken && serverSession !== false;
  const identityLoaded = scope !== null && session.data !== undefined;
  const visibleRemoteProfile =
    identityLoaded && signedIn ? identity.data ?? null : null;
  // A failed status read authorizes nothing: the last answer is withdrawn
  // until a fresh one arrives.
  const visiblePortfolioProfile =
    identityLoaded && signedIn && !sharing.isError && !shareStatusError
      ? sharing.data ?? null
      : null;

  const identityReadError =
    signedIn &&
    identity.isError &&
    !identity.isFetching &&
    !isAccountChange(identity.error)
      ? PARTIAL_READ_ERROR
      : '';
  const portfolioReadError =
    shareStatusError ||
    (signedIn &&
    sharing.isError &&
    !sharing.isFetching &&
    !isAccountChange(sharing.error)
      ? PARTIAL_READ_ERROR
      : '');
  const sessionReadError =
    scopeFailed || (session.isError && !session.isFetching)
      ? 'تعذّر تحديث بيانات الحساب'
      : '';
  const profileError = sessionReadError || identityReadError || portfolioReadError;

  const sessionProfileRevision = Math.max(
    0,
    Number(user.profile_revision) || 0,
  );
  const sessionIdentityIsNewer =
    authenticatedIdentity &&
    sessionProfileRevision > (visibleRemoteProfile?.profileRevision ?? -1);
  const displayName =
    (sessionIdentityIsNewer ? user.name : visibleRemoteProfile?.name) ||
    (authenticatedIdentity ? user.name : '') ||
    'ضيف ركن';
  const certificateHolderName =
    (sessionIdentityIsNewer ? user.name : visibleRemoteProfile?.name) ||
    (authenticatedIdentity ? user.name : '') ||
    '';
  // Empty is an intentional removal; only an absent field may use another
  // profile source. Do not revive an older portfolio headline after a save.
  const sessionHeadline =
    authenticatedIdentity && typeof user.portfolio_headline === 'string'
      ? user.portfolio_headline
      : undefined;
  const role =
    (sessionIdentityIsNewer
      ? sessionHeadline
      : visibleRemoteProfile?.portfolioHeadline) ??
    visiblePortfolioProfile?.headline ??
    sessionHeadline ??
    '';
  const portfolioSharingSuspended = Boolean(
    visiblePortfolioProfile?.sharingSuspended,
  );
  // Only the portfolio response can authorize sharing. The live published
  // version stays shareable while newer edits wait for review; a slug or an
  // older account-profile URL says nothing about publication.
  const portfolioPublished = Boolean(visiblePortfolioProfile?.published);
  const publicPortfolioUrl =
    portfolioPublished && !portfolioSharingSuspended
      ? trustedPortfolioShareUrl(visiblePortfolioProfile?.publicUrl) || ''
      : '';
  const sharingNoticeText =
    hasShareablePortfolio || portfolioSharingSuspended || portfolioPublished
      ? portfolioSharingNotice(visiblePortfolioProfile)
      : '';
  const canSharePortfolio = Boolean(
    serverSession === true &&
      identityLoaded &&
      !portfolioSharingSuspended &&
      publicPortfolioUrl,
  );
  const avatarUri = useMemo(
    () =>
      (sessionIdentityIsNewer
        ? user.avatar || user.profile_image
        : visibleRemoteProfile?.avatar) ||
      (authenticatedIdentity ? user.avatar || user.profile_image : ''),
    [
      authenticatedIdentity,
      user.avatar,
      user.profile_image,
      visibleRemoteProfile?.avatar,
      sessionIdentityIsNewer,
    ],
  );

  const updateShareablePortfolio = useCallback(
    (available: boolean) => {
      const hadWork = hasShareablePortfolioRef.current;
      hasShareablePortfolioRef.current = available;
      setHasShareablePortfolio(available);
      // Gallery changes include edits to an already completed project. Its old
      // approval must not survive just because the number of projects is
      // equal: the status read starts over and shows nothing until it answers.
      if ((available || hadWork) && scopeRef.current !== null) {
        setShareStatusError('');
        void client
          .resetQueries({
            queryKey: accountQueryKeys(scopeRef.current).portfolioProfile(),
            exact: true,
          })
          .catch(() => undefined);
      }
    },
    [client],
  );

  // A saved profile response is authoritative; older in-flight reads lose.
  const applyPortfolioProfile = useCallback(
    (profile: PortfolioProfile) => {
      const owner = scopeRef.current;
      if (owner === null) return;
      const queryKey = accountQueryKeys(owner).portfolioProfile();
      void client.cancelQueries({queryKey, exact: true}).then(() => {
        if (scopeRef.current !== owner) return;
        client.setQueryData<PortfolioProfile>(queryKey, profile);
        setShareStatusError('');
      });
    },
    [client],
  );

  const refreshPortfolioShareUrl = useCallback(async () => {
    const owner = scopeRef.current;
    if (!canSharePortfolio || shareCheckRef.current || owner === null) return;
    const flight = Symbol('portfolio-share-check');
    shareCheckRef.current = flight;
    const isCurrent = () => mountedRef.current && scopeRef.current === owner;
    const queryKey = accountQueryKeys(owner).portfolioProfile();
    try {
      // Moderation can change while this profile remains mounted. Recheck the
      // owner's authoritative sharing status before opening the system sheet;
      // a slower focus read already in flight may not answer after it.
      await client.cancelQueries({queryKey, exact: true});
      const latest = await client.fetchQuery({
        ...portfolioProfileQueryOptions(owner),
        staleTime: 0,
      });
      if (!isCurrent()) return;
      setShareStatusError('');
      if (!latest.published || latest.sharingSuspended) {
        const notice = portfolioSharingNotice(latest);
        Alert.alert(
          'مشاركة الأعمال',
          notice || `${SHARE_STATUS_ERROR}\n${learnerCopy.retry}`,
        );
        return;
      }
      const shareUrl = trustedPortfolioShareUrl(latest.publicUrl);
      if (!shareUrl) throw new Error('PORTFOLIO_SHARE_UNAVAILABLE');
      return shareUrl;
    } catch {
      if (!isCurrent()) return;
      setShareStatusError(SHARE_STATUS_ERROR);
      Alert.alert('تعذّرت المشاركة', learnerCopy.retry);
    } finally {
      if (shareCheckRef.current === flight) shareCheckRef.current = null;
    }
  }, [canSharePortfolio, client]);

  const sharePortfolio = useCallback(async () => {
    const owner = scopeRef.current;
    const shareUrl = await refreshPortfolioShareUrl();
    if (!shareUrl || !mountedRef.current || owner !== scopeRef.current) return;
    try {
      await shareOnce('portfolio', {
        title: `بورتفوليو ${displayName} على ركن`,
        message: `شاهد أعمالي على ركن\n${shareUrl}`,
        url: shareUrl,
      });
    } catch {
      if (!mountedRef.current || owner !== scopeRef.current) return;
      Alert.alert('تعذّرت المشاركة', learnerCopy.retry);
    }
  }, [displayName, refreshPortfolioShareUrl]);

  return {
    authenticatedIdentity,
    avatarUri,
    canSharePortfolio,
    certificateHolderName,
    displayName,
    identityKey,
    profileError,
    portfolioSharingSuspended,
    portfolioSharingNotice: sharingNoticeText,
    publicPortfolioUrl,
    applyPortfolioProfile,
    refreshPortfolioShareUrl,
    retry,
    role,
    setHasShareablePortfolio: updateShareablePortfolio,
    sharePortfolio,
  };
}
