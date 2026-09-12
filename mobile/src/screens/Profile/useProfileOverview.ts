import {useFocusEffect} from '@react-navigation/native';
import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {Alert} from 'react-native';
import {useSelector} from 'react-redux';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  extractApiToken,
  extractUserProfile,
  sessionIdentityKey,
} from '../../constants/helpers';
import {
  getPortfolioProfile,
  getProfile,
  hasSession,
  type PortfolioProfile,
  type Profile as ProfileDto,
} from '../../services/roknApi';
import {trustedPortfolioShareUrl} from '../../services/publicLinks';
import {shareOnce} from '../../services/systemActions';
import type {RootState} from '../../store/store';

const sharingNotice = (profile: PortfolioProfile | null) => {
  switch (profile?.sharingStatus) {
    case 'pending':
      return 'أعمالك قيد المراجعة\nسيظهر رابط المشاركة بعد الموافقة';
    case 'rejected':
      return profile.sharingRejectionReason
        ? `عدّل أعمالك لإتاحتها للمشاركة\n${profile.sharingRejectionReason}`
        : 'عدّل أعمالك لإتاحتها للمشاركة';
    case 'suspended':
      return 'المشاركة موقوفة مؤقتًا\nأعمالك محفوظة ويمكنك التواصل مع الدعم';
    default:
      return '';
  }
};

export function useProfileOverview() {
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const user = extractUserProfile(storedUser);
  const hasStoredToken = Boolean(extractApiToken(storedUser));
  const identityKey = sessionIdentityKey(storedUser);
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [remoteProfile, setRemoteProfile] = useState<ProfileDto | null>(null);
  const [portfolioProfile, setPortfolioProfile] =
    useState<PortfolioProfile | null>(null);
  const [loadedIdentity, setLoadedIdentity] = useState('');
  const [profileError, setProfileError] = useState('');
  const [hasShareablePortfolio, setHasShareablePortfolio] = useState(false);
  const hasShareablePortfolioRef = useRef(false);
  const shareCheckRef = useRef<symbol | null>(null);
  const portfolioReadRef = useRef(0);
  const shareOwnerRef = useRef({identityKey});
  const mountedRef = useRef(true);
  if (shareOwnerRef.current.identityKey !== identityKey) {
    shareOwnerRef.current = {identityKey};
    shareCheckRef.current = null;
  }
  const [reloadProfile, setReloadProfile] = useState(0);
  const reloadProfileRef = useRef(reloadProfile);
  reloadProfileRef.current = reloadProfile;

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const authenticatedIdentity = hasStoredToken && serverSession !== false;
  const identityLoaded = loadedIdentity === identityKey;
  const visibleRemoteProfile = identityLoaded ? remoteProfile : null;
  const visiblePortfolioProfile = identityLoaded ? portfolioProfile : null;
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
  // Only the reviewed portfolio response can authorize sharing. A slug or an
  // older account-profile URL says nothing about the current review decision.
  const publicPortfolioUrl =
    visiblePortfolioProfile?.sharingStatus === 'approved' &&
    !portfolioSharingSuspended
      ? trustedPortfolioShareUrl(visiblePortfolioProfile.publicUrl) || ''
      : '';
  const portfolioSharingNotice =
    hasShareablePortfolio || portfolioSharingSuspended
      ? sharingNotice(visiblePortfolioProfile)
      : '';
  const canSharePortfolio = Boolean(
    serverSession === true &&
      identityLoaded &&
      !portfolioSharingSuspended &&
      hasShareablePortfolio &&
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

  useEffect(() => {
    setServerSession(null);
    setRemoteProfile(null);
    setPortfolioProfile(null);
    setLoadedIdentity('');
    setProfileError('');
    setHasShareablePortfolio(false);
    hasShareablePortfolioRef.current = false;
  }, [identityKey]);

  const updateShareablePortfolio = useCallback((available: boolean) => {
    const hadWork = hasShareablePortfolioRef.current;
    hasShareablePortfolioRef.current = available;
    setHasShareablePortfolio(available);
    // Gallery changes include edits to an already completed project. Its old
    // approval must not survive just because the number of projects is equal.
    if (available || hadWork) {
      portfolioReadRef.current += 1;
      setPortfolioProfile(null);
      setReloadProfile(value => value + 1);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      let active = true;
      const requestRevision = reloadProfile;
      const portfolioRead = ++portfolioReadRef.current;
      void (async () => {
        try {
          setProfileError('');
          const boundary = await captureAccountSessionBoundary();
          const sessionAvailable = await hasSession();
          assertAccountSessionBoundary(boundary);
          if (!active || requestRevision !== reloadProfileRef.current) return;
          setServerSession(sessionAvailable);
          if (!sessionAvailable) {
            setRemoteProfile(null);
            setPortfolioProfile(null);
            setLoadedIdentity(identityKey);
            return;
          }
          const [profileResult, portfolioResult] = await Promise.allSettled([
            getProfile(boundary),
            getPortfolioProfile(boundary),
          ]);
          assertAccountSessionBoundary(boundary);
          if (!active || requestRevision !== reloadProfileRef.current) return;
          if (profileResult.status === 'fulfilled') {
            setRemoteProfile(profileResult.value);
          }
          if (portfolioRead === portfolioReadRef.current) {
            setPortfolioProfile(
              portfolioResult.status === 'fulfilled'
                ? portfolioResult.value
                : null,
            );
          }
          setLoadedIdentity(identityKey);
          if (
            profileResult.status === 'rejected' ||
            (portfolioRead === portfolioReadRef.current &&
              portfolioResult.status === 'rejected')
          ) {
            setProfileError('تعذّر تحديث بعض بيانات الحساب');
          }
        } catch (error: unknown) {
          if (!active || requestRevision !== reloadProfileRef.current) return;
          if (
            error instanceof Error &&
            error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
          ) {
            return;
          }
          setProfileError('تعذّر تحديث بيانات الحساب');
        }
      })();
      return () => {
        active = false;
      };
    }, [identityKey, reloadProfile]),
  );

  const retry = useCallback(() => setReloadProfile(value => value + 1), []);

  const refreshPortfolioShareUrl = useCallback(async () => {
    if (!canSharePortfolio || shareCheckRef.current) return;
    const flight = Symbol('portfolio-share-check');
    shareCheckRef.current = flight;
    const portfolioRead = ++portfolioReadRef.current;
    const owner = shareOwnerRef.current;
    const requestRevision = reloadProfileRef.current;
    const isCurrent = () =>
      mountedRef.current &&
      owner === shareOwnerRef.current &&
      portfolioRead === portfolioReadRef.current &&
      requestRevision === reloadProfileRef.current;
    try {
      // Moderation can change while this profile remains mounted. Recheck the
      // owner's authoritative sharing status before opening the system sheet.
      const boundary = await captureAccountSessionBoundary();
      const latest = await getPortfolioProfile(boundary);
      assertAccountSessionBoundary(boundary);
      if (!isCurrent()) return;
      setPortfolioProfile(latest);
      if (latest.sharingStatus !== 'approved' || latest.sharingSuspended) {
        const notice = sharingNotice(latest);
        Alert.alert(
          'مشاركة الأعمال',
          notice || 'تعذّر تحديث حالة المشاركة\nحاول مرة أخرى',
        );
        return;
      }
      const shareUrl = trustedPortfolioShareUrl(latest.publicUrl);
      if (!shareUrl) throw new Error('PORTFOLIO_SHARE_UNAVAILABLE');
      return shareUrl;
    } catch {
      if (!isCurrent()) return;
      setPortfolioProfile(null);
      setProfileError('تعذّر تحديث حالة المشاركة');
      Alert.alert('تعذّرت المشاركة', 'حاول مرة أخرى');
    } finally {
      if (shareCheckRef.current === flight) shareCheckRef.current = null;
    }
  }, [canSharePortfolio]);

  const sharePortfolio = useCallback(async () => {
    const owner = shareOwnerRef.current;
    const shareUrl = await refreshPortfolioShareUrl();
    if (!shareUrl || !mountedRef.current || owner !== shareOwnerRef.current)
      return;
    try {
      await shareOnce('portfolio', {
        title: `بورتفوليو ${displayName} على ركن`,
        message: `شاهد أعمالي على ركن\n${shareUrl}`,
        url: shareUrl,
      });
    } catch {
      if (!mountedRef.current || owner !== shareOwnerRef.current) return;
      Alert.alert('تعذّرت المشاركة', 'حاول مرة أخرى');
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
    portfolioSharingNotice,
    publicPortfolioUrl,
    refreshPortfolioShareUrl,
    retry,
    role,
    setHasShareablePortfolio: updateShareablePortfolio,
    sharePortfolio,
  };
}
