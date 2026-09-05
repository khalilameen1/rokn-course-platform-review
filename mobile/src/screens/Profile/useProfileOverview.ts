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
import {
  portfolioUrlFor,
  trustedPortfolioShareUrl,
} from '../../services/publicLinks';
import {openExternalUrlOnce, shareOnce} from '../../services/systemActions';
import type {RootState} from '../../store/store';

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
  const publicPortfolioUrlRef = useRef('');
  const [reloadProfile, setReloadProfile] = useState(0);
  const reloadProfileRef = useRef(reloadProfile);
  reloadProfileRef.current = reloadProfile;

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
  const role =
    (sessionIdentityIsNewer
      ? typeof user.portfolio_headline === 'string'
        ? user.portfolio_headline
        : ''
      : visibleRemoteProfile?.portfolioHeadline) ||
    visiblePortfolioProfile?.headline ||
    (authenticatedIdentity && typeof user.portfolio_headline === 'string'
      ? user.portfolio_headline
      : '') ||
    '';
  const username =
    visiblePortfolioProfile?.slug ||
    visibleRemoteProfile?.portfolioSlug ||
    (authenticatedIdentity ? user.portfolio_slug || user.username : '') ||
    '';
  const publicPortfolioUrl =
    trustedPortfolioShareUrl(visiblePortfolioProfile?.publicUrl) ||
    trustedPortfolioShareUrl(visibleRemoteProfile?.portfolioUrl) ||
    trustedPortfolioShareUrl(username ? portfolioUrlFor(username) : '') ||
    '';
  publicPortfolioUrlRef.current = publicPortfolioUrl;
  const canSharePortfolio = Boolean(
    serverSession === true &&
      identityLoaded &&
      hasShareablePortfolio &&
      publicPortfolioUrl,
  );
  const portfolioLinkLabel = publicPortfolioUrl
    ? publicPortfolioUrl
        .replace(/^https:\/\/(?:www\.)?/i, '')
        .replace(/\/$/, '')
    : '';
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
    const becameShareable = available && !hasShareablePortfolioRef.current;
    hasShareablePortfolioRef.current = available;
    setHasShareablePortfolio(available);
    // The unlisted URL is issued by portfolio-profile. If that independent
    // read failed before the first project became publishable, refresh it once
    // instead of rendering a share action that silently does nothing.
    if (becameShareable && !publicPortfolioUrlRef.current) {
      setReloadProfile(value => value + 1);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      let active = true;
      const requestRevision = reloadProfile;
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
          if (portfolioResult.status === 'fulfilled') {
            setPortfolioProfile(portfolioResult.value);
          }
          setLoadedIdentity(identityKey);
          if (
            profileResult.status === 'rejected' ||
            portfolioResult.status === 'rejected'
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

  const sharePortfolio = useCallback(async () => {
    if (!canSharePortfolio) return;
    try {
      await shareOnce('portfolio', {
        title: `بورتفوليو ${displayName} على ركن`,
        message: `شاهد أعمالي على ركن\n${publicPortfolioUrl}`,
        url: publicPortfolioUrl,
      });
    } catch {
      Alert.alert('تعذّرت المشاركة', 'حاول مرة أخرى');
    }
  }, [canSharePortfolio, displayName, publicPortfolioUrl]);

  const openPortfolio = useCallback(async () => {
    if (!canSharePortfolio) return;
    try {
      await openExternalUrlOnce(publicPortfolioUrl);
    } catch {
      Alert.alert('تعذّر فتح الرابط', 'حاول مرة أخرى');
    }
  }, [canSharePortfolio, publicPortfolioUrl]);

  return {
    authenticatedIdentity,
    avatarUri,
    canSharePortfolio,
    certificateHolderName,
    displayName,
    identityKey,
    openPortfolio,
    portfolioLinkLabel,
    profileError,
    publicPortfolioUrl,
    retry,
    role,
    setHasShareablePortfolio: updateShareablePortfolio,
    sharePortfolio,
  };
}
