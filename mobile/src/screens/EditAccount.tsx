import {useIsFocused, useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../navigation/types';
import React, {useEffect, useMemo, useRef, useState} from 'react';
import {
  ActivityIndicator,
  Alert,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import {RasterImage as Image} from '../components/ui/RasterImage';
import {launchImageLibrary} from 'react-native-image-picker';
import {useDispatch, useSelector} from 'react-redux';
import Button from '../components/touchables/Button';
import {Container, Content} from '../components/containers/Containers';
import {ResponsiveFrame, StatusView} from '../components/ui/PremiumUI';
import HeaderWithBack from '../components/view/HeaderWithBack';
import {
  AsyncKeys,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  extractApiToken,
  extractUserProfile,
  getItem,
  sessionIdentityKey,
} from '../constants/helpers';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
} from '../constants/designSystem';
import {saveLoginData} from '../store/reducers/auth';
import {
  peekSecureSession,
  updateSecureSessionForOwner,
} from '../services/secureSession';
import {getProfile, hasSession, updateProfile} from '../services/roknApi';
import type {RootState} from '../store/store';
import {asRecord, learnerErrorMessage} from '../utils/errorPayload';
import {
  cacheLearnerDraftFile,
  removeLearnerDraftFile,
  type LearnerDraftFile,
} from '../services/learnerDraftFiles';
import {secureRandomUuid} from '../utils/secureRandom';
import {showMediaPickerFailure} from '../services/mediaPickerErrors';
import {DefaultAvatar} from '../components/ui/DefaultAvatar';
import {settleWithin} from '../utils/settleWithin';
import {useAppForegroundState} from '../hooks/useAppActiveState';

const discardAvatar = (file: Parameters<typeof removeLearnerDraftFile>[0]) => {
  void removeLearnerDraftFile(file).catch(() => undefined);
};

export default function EditAccount() {
  const navigation = useNavigation<RootNavigation>();
  const focused = useIsFocused();
  const foreground = useAppForegroundState();
  const dispatch = useDispatch();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const user = extractUserProfile(storedUser);
  const accountToken = extractApiToken(storedUser);
  const hasStoredToken = Boolean(accountToken);
  const identityKey = sessionIdentityKey(storedUser);
  const [name, setName] = useState(user.name ?? '');
  const [portfolioHeadline, setPortfolioHeadline] = useState(
    hasStoredToken && typeof user.portfolio_headline === 'string'
      ? user.portfolio_headline
      : '',
  );
  const [email, setEmail] = useState(user.email ?? '');
  const storedAvatar = user.avatar || user.profile_image;
  const [avatar, setAvatar] = useState(storedAvatar || '');
  const [failedAvatarUri, setFailedAvatarUri] = useState<string>();
  const [profileRevision, setProfileRevision] = useState(0);
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [hydrationState, setHydrationState] = useState<
    'loading' | 'ready' | 'error'
  >('loading');
  const [reloadProfile, setReloadProfile] = useState(0);
  const [saving, setSaving] = useState(false);
  const [preparingAvatar, setPreparingAvatar] = useState(false);
  const normalizedName = name.trim().replace(/\s+/g, ' ');
  const normalizedPortfolioHeadline = portfolioHeadline
    .trim()
    .replace(/\s+/g, ' ');
  const validName = Array.from(normalizedName).length >= 2;
  const mountedRef = useRef(true);
  const saveFlightRef = useRef(false);
  // Keep one physical native picker/copy flight even after navigating away.
  // A new focus visit may not adopt a result belonging to the previous visit.
  const pickerFlightRef = useRef<symbol | null>(null);
  const editorVisit = useMemo(
    () => ({identityKey, accountToken, focused}),
    [identityKey, accountToken, focused],
  );
  const editorVisitRef = useRef(editorVisit);
  editorVisitRef.current = editorVisit;
  // A native gallery temporarily backgrounds the app. It owns the editor visit,
  // not this narrower owner of save alerts and navigation.
  const savePresentationVisit = useMemo(
    () => ({editorVisit, foreground}),
    [editorVisit, foreground],
  );
  const savePresentationVisitRef = useRef(savePresentationVisit);
  savePresentationVisitRef.current = savePresentationVisit;
  const identityRef = useRef({identityKey, accountToken});
  const avatarUploadRef = useRef<LearnerDraftFile | undefined>(undefined);
  const profileRequestRef = useRef<{fingerprint: string; id: string} | null>(
    null,
  );
  const profileBaselineRef = useRef({
    identityKey,
    accountToken,
    name: user.name ?? '',
    portfolioHeadline:
      typeof user.portfolio_headline === 'string'
        ? user.portfolio_headline
        : '',
    avatar: storedAvatar || '',
    profileRevision: Math.max(0, Number(user.profile_revision) || 0),
  });
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      if (!saveFlightRef.current) {
        discardAvatar(avatarUploadRef.current);
      }
    };
  }, []);

  useEffect(() => {
    if (
      identityRef.current.identityKey === identityKey &&
      identityRef.current.accountToken === accountToken
    )
      return;
    identityRef.current = {identityKey, accountToken};
    const staleDraft = avatarUploadRef.current;
    avatarUploadRef.current = undefined;
    profileRequestRef.current = null;
    const nextUser = extractUserProfile(storedUser);
    const nextBaseline = {
      identityKey,
      accountToken,
      name: nextUser.name ?? '',
      portfolioHeadline:
        typeof nextUser.portfolio_headline === 'string'
          ? nextUser.portfolio_headline
          : '',
      avatar: nextUser.avatar || nextUser.profile_image || '',
      profileRevision: Math.max(0, Number(nextUser.profile_revision) || 0),
    };
    setName(nextBaseline.name);
    setPortfolioHeadline(nextBaseline.portfolioHeadline);
    setEmail(nextUser.email ?? '');
    setAvatar(nextBaseline.avatar);
    setProfileRevision(0);
    setServerSession(null);
    profileBaselineRef.current = nextBaseline;
    discardAvatar(staleDraft);
  }, [identityKey, accountToken, storedUser]);

  useEffect(() => {
    let active = true;
    const readRevision = profileBaselineRef.current.profileRevision;
    void (async () => {
      if (active) setHydrationState('loading');
      if (!hasStoredToken) {
        setServerSession(false);
        setHydrationState('error');
        return;
      }
      const sessionAvailable = await hasSession();
      if (!active) return;
      setServerSession(sessionAvailable);
      if (!sessionAvailable) {
        setHydrationState('error');
        return;
      }
      const boundary = await captureAccountSessionBoundary();
      const profileResult = await getProfile(boundary).then(
        value => ({status: 'fulfilled' as const, value}),
        reason => ({status: 'rejected' as const, reason}),
      );
      assertAccountSessionBoundary(boundary);
      if (
        !active ||
        profileBaselineRef.current.profileRevision > readRevision
      ) {
        // A newer canonical commit already unlocked this editor. Even an
        // equal-revision GET must not replay over work typed after that commit.
        return;
      }
      if (active && profileResult.status === 'fulfilled') {
        const profile = profileResult.value;
        profileBaselineRef.current = {
          identityKey,
          accountToken,
          name: profile.name,
          portfolioHeadline: profile.portfolioHeadline,
          avatar: profile.avatar || '',
          profileRevision: profile.profileRevision,
        };
        setName(profile.name);
        setPortfolioHeadline(profile.portfolioHeadline);
        setEmail(profile.email);
        setAvatar(profile.avatar || '');
        setProfileRevision(profile.profileRevision);
      }
      if (active) {
        setHydrationState(
          profileResult.status === 'fulfilled' ? 'ready' : 'error',
        );
      }
    })().catch(() => {
      if (active && profileBaselineRef.current.profileRevision <= readRevision)
        setHydrationState('error');
    });
    return () => {
      active = false;
    };
  }, [hasStoredToken, identityKey, accountToken, reloadProfile]);

  useEffect(() => {
    const baseline = profileBaselineRef.current;
    const current = peekSecureSession();
    const sharedProfile = extractUserProfile(storedUser);
    const committedRevision = Math.max(
      0,
      Number(sharedProfile.profile_revision) || 0,
    );
    if (
      baseline.identityKey !== identityKey ||
      baseline.accountToken !== accountToken ||
      committedRevision <= baseline.profileRevision ||
      !current.ready ||
      sessionIdentityKey(current.session) !== identityKey ||
      extractApiToken(current.session) !== accountToken
    )
      return;
    const committedUser = extractUserProfile(current.session);
    if (Number(committedUser.profile_revision) !== committedRevision) return;
    const next = {
      identityKey,
      accountToken,
      name: committedUser.name ?? '',
      portfolioHeadline:
        typeof committedUser.portfolio_headline === 'string'
          ? committedUser.portfolio_headline
          : '',
      avatar: committedUser.avatar || committedUser.profile_image || '',
      profileRevision: committedRevision,
    };
    // Follow the existing committed account snapshot, not a second profile
    // cache. Merge only untouched fields; a reopened editor may hold new work.
    setName(value => (value === baseline.name ? next.name : value));
    setPortfolioHeadline(value =>
      value === baseline.portfolioHeadline ? next.portfolioHeadline : value,
    );
    if (!avatarUploadRef.current) setAvatar(next.avatar);
    setEmail(committedUser.email ?? '');
    setProfileRevision(next.profileRevision);
    profileBaselineRef.current = next;
    setServerSession(true);
    setHydrationState('ready');
  }, [storedUser, identityKey, accountToken]);

  const chooseAvatar = async () => {
    if (
      !mountedRef.current ||
      !editorVisit.focused ||
      editorVisitRef.current !== editorVisit ||
      serverSession !== true ||
      hydrationState !== 'ready' ||
      pickerFlightRef.current ||
      saveFlightRef.current
    )
      return;
    const token = Symbol('account-avatar-preparation');
    pickerFlightRef.current = token;
    setPreparingAvatar(true);
    const ownsSelection = () =>
      mountedRef.current &&
      editorVisitRef.current === editorVisit &&
      pickerFlightRef.current === token;
    let cachedSelection: LearnerDraftFile | undefined;
    try {
      const pickerBoundary = await captureAccountSessionBoundary();
      assertAccountSessionBoundary(pickerBoundary);
      if (!ownsSelection()) return;
      const result = await launchImageLibrary({
        mediaType: 'photo',
        selectionLimit: 1,
        quality: 0.8,
      });
      assertAccountSessionBoundary(pickerBoundary);
      if (!ownsSelection() || result.didCancel) return;
      if (result.errorCode) {
        showMediaPickerFailure(result.errorCode);
        return;
      }
      const asset = result.assets?.[0];
      if (asset?.fileSize && asset.fileSize > 2 * 1024 * 1024) {
        Alert.alert('الصورة كبيرة', 'اختر صورة أصغر من ٢ ميجابايت');
        return;
      }
      if (asset?.uri) {
        const cached = await cacheLearnerDraftFile(
          'avatar',
          {
            uri: asset.uri,
            type: asset.type,
            fileName: asset.fileName,
            size: asset.fileSize,
          },
          2 * 1024 * 1024,
          pickerBoundary,
        );
        cachedSelection = cached;
        assertAccountSessionBoundary(pickerBoundary);
        if (!ownsSelection()) {
          discardAvatar(cached);
          cachedSelection = undefined;
          return;
        }
        const previous = avatarUploadRef.current;
        // Transfer file ownership before React renders or saving is unlocked.
        avatarUploadRef.current = cached;
        setAvatar(cached.uri);
        cachedSelection = undefined;
        profileRequestRef.current = null;
        discardAvatar(previous);
      }
    } catch (error: unknown) {
      discardAvatar(cachedSelection);
      if (
        error instanceof Error &&
        error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      )
        return;
      if (ownsSelection()) {
        showMediaPickerFailure(
          typeof error === 'object' && error && 'errorCode' in error
            ? String(error.errorCode)
            : undefined,
        );
      }
    } finally {
      if (pickerFlightRef.current === token) {
        pickerFlightRef.current = null;
        if (mountedRef.current) setPreparingAvatar(false);
      }
    }
  };

  const save = async () => {
    const currentSession = peekSecureSession();
    if (
      !mountedRef.current ||
      !editorVisit.focused ||
      !foreground ||
      editorVisitRef.current !== editorVisit ||
      savePresentationVisitRef.current !== savePresentationVisit ||
      !currentSession.ready ||
      sessionIdentityKey(currentSession.session) !== identityKey ||
      extractApiToken(currentSession.session) !== accountToken ||
      serverSession !== true ||
      hydrationState !== 'ready' ||
      !validName ||
      pickerFlightRef.current ||
      saveFlightRef.current
    )
      return;
    const selectedAvatar = avatarUploadRef.current;
    saveFlightRef.current = true;
    setSaving(true);
    const ownsPresentation = () =>
      mountedRef.current &&
      savePresentationVisitRef.current === savePresentationVisit;
    const sameEditorAccount = () =>
      mountedRef.current &&
      editorVisitRef.current.identityKey === identityKey &&
      editorVisitRef.current.accountToken === accountToken;
    let remoteProfileSaved = false;
    let sessionAtStart: unknown;
    let remoteName = normalizedName;
    let remotePortfolioHeadline = normalizedPortfolioHeadline;
    let remoteAvatar = storedAvatar;
    let remoteProfileRevision = profileRevision;
    // Saving is an accepted account intent, not a disposable focus effect.
    // Settling its canonical form is safe while controls remain flight-locked;
    // navigation and alerts still belong only to the originating focus visit.
    const applyCommittedForm = () => {
      if (!sameEditorAccount()) return;
      profileBaselineRef.current = {
        identityKey,
        accountToken,
        name: remoteName,
        portfolioHeadline: remotePortfolioHeadline,
        avatar: remoteAvatar || '',
        profileRevision: remoteProfileRevision,
      };
      setName(remoteName);
      setPortfolioHeadline(remotePortfolioHeadline);
      setAvatar(remoteAvatar || '');
      setProfileRevision(remoteProfileRevision);
    };
    const releaseSelectedAvatar = () => {
      discardAvatar(selectedAvatar);
      if (avatarUploadRef.current === selectedAvatar)
        avatarUploadRef.current = undefined;
    };
    try {
      const accountBoundary = await captureAccountSessionBoundary();
      sessionAtStart = await getItem(AsyncKeys.USER_DATA);
      assertAccountSessionBoundary(accountBoundary);
      if (
        sessionIdentityKey(sessionAtStart) !== identityKey ||
        extractApiToken(sessionAtStart) !== accountToken
      )
        throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
      const ownerAtStart = extractUserProfile(sessionAtStart);
      const expectedOwner = String(
        ownerAtStart.id ?? ownerAtStart.user_id ?? '',
      ).trim();
      if (!expectedOwner) {
        throw new Error('PROFILE_SESSION_OWNER_UNAVAILABLE');
      }
      if (serverSession) {
        assertAccountSessionBoundary(accountBoundary);
        const requestFingerprint = JSON.stringify([
          normalizedName,
          normalizedPortfolioHeadline,
          selectedAvatar?.uri || '',
          selectedAvatar?.size || 0,
          profileRevision,
        ]);
        if (profileRequestRef.current?.fingerprint !== requestFingerprint) {
          profileRequestRef.current = {
            fingerprint: requestFingerprint,
            id: secureRandomUuid(),
          };
        }
        const profile = await updateProfile(
          {
            name: normalizedName,
            avatar: selectedAvatar,
            portfolioHeadline: normalizedPortfolioHeadline,
            clientRequestId: profileRequestRef.current.id,
            expectedProfileRevision: profileRevision,
          },
          accountBoundary,
        );
        assertAccountSessionBoundary(accountBoundary);
        if (selectedAvatar && !profile.avatar) {
          throw new Error('PROFILE_AVATAR_NOT_PERSISTED');
        }
        remoteName = profile.name;
        remotePortfolioHeadline = profile.portfolioHeadline;
        remoteAvatar = profile.avatar || remoteAvatar;
        remoteProfileRevision = profile.profileRevision;
        remoteProfileSaved = true;
      }
      assertAccountSessionBoundary(accountBoundary);
      const sessionWrite = updateSecureSessionForOwner(
        expectedOwner,
        activeSession => {
          assertAccountSessionBoundary(accountBoundary);
          const activeRecord = asRecord(activeSession) ?? {};
          const activeUser = extractUserProfile(activeSession);
          return {
            ...activeRecord,
            user: {
              ...activeUser,
              name: remoteName,
              portfolio_headline: remotePortfolioHeadline,
              avatar: remoteAvatar,
              profile_image: remoteAvatar,
              image: remoteAvatar,
              profile_revision: remoteProfileRevision,
            },
          };
        },
      );
      // The server has committed. A slow local mirror must not hold the form
      // indefinitely; its real session mutation still retains queue ownership.
      const persisted = await settleWithin(
        sessionWrite.then(() => true),
        false,
      );
      const current = peekSecureSession();
      if (
        !current.ready ||
        sessionIdentityKey(current.session) !==
          sessionIdentityKey(sessionAtStart) ||
        extractApiToken(current.session) !== extractApiToken(sessionAtStart)
      )
        return;
      if (!persisted) throw new Error('PROFILE_SESSION_CACHE_UNAVAILABLE');
      // The mirror advances the epoch itself. Use the current committed
      // snapshot, never the pre-write boundary or a superseded profile result.
      dispatch(saveLoginData(current.session));
      profileRequestRef.current = null;
      releaseSelectedAvatar();
      applyCommittedForm();
      if (ownsPresentation()) {
        navigation.goBack();
      }
    } catch (error: unknown) {
      if (
        error instanceof Error &&
        error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      ) {
        return;
      }
      const current = peekSecureSession();
      if (
        sessionAtStart &&
        (sessionIdentityKey(current.session) !==
          sessionIdentityKey(sessionAtStart) ||
          extractApiToken(current.session) !== extractApiToken(sessionAtStart))
      )
        return;
      if (remoteProfileSaved) {
        profileRequestRef.current = null;
        releaseSelectedAvatar();
        applyCommittedForm();
        if (sameEditorAccount()) {
          setHydrationState('loading');
          setReloadProfile(value => value + 1);
        }
        if (ownsPresentation())
          Alert.alert('حُفظت التغييرات', 'ستظهر عند فتح الصفحة من جديد');
      } else if (ownsPresentation()) {
        Alert.alert(
          'تعذّر حفظ التغييرات',
          learnerErrorMessage(error, 'لم تكتمل التغييرات\nحاول مرة أخرى'),
        );
      }
    } finally {
      saveFlightRef.current = false;
      if (mountedRef.current) {
        setSaving(false);
      } else {
        discardAvatar(avatarUploadRef.current);
      }
    }
  };

  return (
    <Container noPadding>
      <Content noPadding>
        <ResponsiveFrame style={styles.frame}>
          <HeaderWithBack title="بيانات الحساب" />
          {!hasStoredToken || serverSession === false ? (
            <StatusView
              actionLabel="سجّل الدخول"
              description="بيانات الحساب مرتبطة بطريقة الدخول التي اخترتها"
              onAction={() =>
                navigation.replace('Login', {
                  returnTo: {name: 'EditAccount'},
                })
              }
              state="error"
              title="سجّل الدخول لتعديل حسابك"
            />
          ) : hydrationState === 'loading' || serverSession === null ? (
            <StatusView state="loading" title="جارٍ تحميل بيانات الحساب" />
          ) : hydrationState === 'error' ? (
            <StatusView
              actionLabel="إعادة المحاولة"
              description="حاول مرة أخرى"
              onAction={() => setReloadProfile(value => value + 1)}
              state="error"
              title="تعذّر تحديث بيانات الحساب"
            />
          ) : (
            <>
              <View style={styles.avatarArea}>
                <Pressable
                  accessibilityLabel="اختيار صورة الحساب"
                  accessibilityRole="button"
                  accessibilityState={{
                    busy: preparingAvatar,
                    disabled:
                      saving || preparingAvatar || hydrationState !== 'ready',
                  }}
                  disabled={
                    saving || preparingAvatar || hydrationState !== 'ready'
                  }
                  onPress={chooseAvatar}
                  style={({pressed}) => [
                    styles.avatarButton,
                    pressed && styles.avatarPressed,
                  ]}>
                  <View
                    accessibilityElementsHidden
                    importantForAccessibility="no-hide-descendants">
                    {avatar && avatar !== failedAvatarUri ? (
                      <Image
                        onError={() => setFailedAvatarUri(avatar)}
                        source={{uri: avatar}}
                        style={styles.avatar}
                      />
                    ) : (
                      <DefaultAvatar
                        accessibilityLabel="صورة الحساب"
                        size={104}
                      />
                    )}
                  </View>
                </Pressable>
                <Pressable
                  accessibilityLabel="تغيير صورة الحساب"
                  accessibilityRole="button"
                  accessibilityState={{
                    busy: preparingAvatar,
                    disabled:
                      saving || preparingAvatar || hydrationState !== 'ready',
                  }}
                  disabled={
                    saving || preparingAvatar || hydrationState !== 'ready'
                  }
                  onPress={chooseAvatar}
                  style={styles.changePhoto}>
                  {preparingAvatar ? (
                    <ActivityIndicator
                      accessibilityElementsHidden
                      importantForAccessibility="no"
                      color={Palette.primary}
                      size="small"
                    />
                  ) : null}
                  <Text
                    accessibilityLiveRegion="polite"
                    style={styles.changePhotoLabel}>
                    {preparingAvatar ? 'جارٍ تجهيز الصورة' : 'تغيير الصورة'}
                  </Text>
                </Pressable>
              </View>
              <View style={styles.form}>
                <Text accessibilityRole="header" style={styles.sectionTitle}>
                  هويتك في ركن
                </Text>
                <Text style={styles.label}>الاسم الظاهر</Text>
                <TextInput
                  accessibilityLabel="الاسم الظاهر"
                  autoCapitalize="words"
                  editable={!saving}
                  maxLength={120}
                  onChangeText={setName}
                  style={styles.input}
                  value={name}
                />
                <Text style={styles.label}>
                  العنوان المهني في البورتفوليو (اختياري)
                </Text>
                <TextInput
                  accessibilityLabel="العنوان المهني في البورتفوليو"
                  editable={!saving}
                  maxLength={160}
                  onChangeText={setPortfolioHeadline}
                  placeholder="مصمم منتجات رقمية"
                  placeholderTextColor={Palette.textFaint}
                  style={styles.input}
                  value={portfolioHeadline}
                />
              </View>
              <View style={styles.accountSection}>
                <Text style={styles.label}>البريد المرتبط بالحساب</Text>
                <View style={[styles.input, styles.readonly]}>
                  <Text selectable style={styles.readonlyText}>
                    {email || 'غير متاح'}
                  </Text>
                </View>
                <Text style={styles.hint}>
                  يتبع حساب Google أو Facebook أو TikTok أو Apple الذي سجلت به
                </Text>
              </View>
              <Button
                disable={
                  saving ||
                  preparingAvatar ||
                  hydrationState !== 'ready' ||
                  !validName
                }
                loader={saving}
                onPress={save}
                title="حفظ التغييرات"
              />
            </>
          )}
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}

const styles = StyleSheet.create({
  frame: {maxWidth: 680},
  avatarArea: {
    alignItems: 'center',
    paddingTop: Spacing.sm,
    paddingBottom: Spacing.xl,
  },
  avatarButton: {
    width: 104,
    height: 104,
    borderRadius: 52,
  },
  avatarPressed: {opacity: 0.78},
  avatar: {
    width: 104,
    height: 104,
    borderRadius: 52,
    borderWidth: 2,
    borderColor: Palette.line,
  },
  changePhoto: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.xs,
    minHeight: 48,
    justifyContent: 'center',
    paddingHorizontal: Spacing.md,
  },
  changePhotoLabel: {...Type.bodyStrong, color: '#8BB5FF'},
  form: {paddingBottom: Spacing.xl},
  sectionTitle: {
    ...Type.section,
    ...textDirection,
    color: Palette.text,
    marginBottom: Spacing.sm,
  },
  accountSection: {
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Palette.line,
    paddingTop: Spacing.md,
    paddingBottom: Spacing.xl,
  },
  label: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
    marginTop: Spacing.sm,
    marginBottom: Spacing.xs,
  },
  input: {
    ...Type.body,
    ...textDirection,
    color: Palette.text,
    minHeight: 52,
    borderRadius: Radius.md,
    backgroundColor: Palette.surface,
    borderWidth: 1,
    borderColor: Palette.line,
    paddingHorizontal: Spacing.md,
    paddingVertical: Spacing.sm,
  },
  hint: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textFaint,
    marginTop: Spacing.xs,
  },
  readonly: {
    justifyContent: 'center',
    backgroundColor: 'transparent',
    borderWidth: 0,
    paddingHorizontal: 0,
  },
  readonlyText: {
    ...Type.body,
    direction: 'ltr',
    writingDirection: 'ltr',
    textAlign: 'left',
    color: Palette.textMuted,
  },
});
