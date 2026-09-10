import {useFocusEffect, useNavigation} from '@react-navigation/native';
import React, {useCallback, useRef, useState} from 'react';
import {
  ActivityIndicator,
  Alert,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {useSelector} from 'react-redux';
import Svg, {Circle, Rect} from 'react-native-svg';
import {Container, Content} from '../components/containers/Containers';
import {ResponsiveFrame, StatusView} from '../components/ui/PremiumUI';
import HeaderWithBack from '../components/view/HeaderWithBack';
import {formatRoknDate} from '../utils/dateTime';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../constants/designSystem';
import {
  getDeviceSessions,
  revokeDeviceSession,
  revokeOtherDeviceSessions,
  type DeviceSession,
} from '../services/deviceSessions';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  extractApiToken,
  sessionIdentityKey,
} from '../constants/helpers';
import {
  currentDeviceClass,
  type RoknDeviceClass,
} from '../constants/deviceClass';
import {openGuestLogin} from '../navigation/journeyNavigation';
import type {RootNavigation} from '../navigation/types';
import type {RootState} from '../store/store';

const dateLabel = (value?: string | null) => {
  if (!value) return 'غير معروف';
  return (
    formatRoknDate(value, {
      dateStyle: 'medium',
      timeStyle: 'short',
    }) || 'غير معروف'
  );
};

const deviceClassForSession = (session: DeviceSession): RoknDeviceClass =>
  session.device_class || (session.current ? currentDeviceClass() : 'phone');

const sessionLabel = (session: DeviceSession) => {
  const tablet = deviceClassForSession(session) === 'tablet';
  if (session.platform === 'android') {
    return tablet ? 'جهاز لوحي Android' : 'هاتف Android';
  }
  if (session.platform === 'ios') return tablet ? 'iPad' : 'iPhone';
  return tablet ? 'جهاز لوحي' : 'هاتف';
};

const SessionDeviceIcon = ({deviceClass}: {deviceClass: RoknDeviceClass}) => {
  const tablet = deviceClass === 'tablet';
  return (
    <Svg height={26} viewBox="0 0 26 26" width={26}>
      <Rect
        fill="none"
        height={tablet ? 20 : 22}
        rx={tablet ? 2.8 : 4}
        stroke={Palette.primary}
        strokeWidth={1.8}
        width={tablet ? 17 : 13}
        x={tablet ? 4.5 : 6.5}
        y={tablet ? 3 : 2}
      />
      <Circle cx={13} cy={tablet ? 19.5 : 20.5} fill={Palette.primary} r={1} />
    </Svg>
  );
};

export default function DeviceSessions() {
  const navigation = useNavigation<RootNavigation>();
  const insets = useSafeAreaInsets();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const authenticated = Boolean(extractApiToken(storedUser));
  const accountIdentity = sessionIdentityKey(storedUser);
  const [sessions, setSessions] = useState<DeviceSession[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [removing, setRemoving] = useState<string | null>(null);
  const [error, setError] = useState('');
  const loadGenerationRef = useRef(0);
  const mutationFlightRef = useRef<{identity: string} | null>(null);
  const pendingRefreshRef = useRef<string | null>(null);
  const screenActiveRef = useRef(false);
  const screenVisitRef = useRef(0);
  const accountIdentityRef = useRef(accountIdentity);
  accountIdentityRef.current = accountIdentity;

  const load = useCallback(
    async (refresh = false, requestedIdentity = accountIdentityRef.current) => {
      if (mutationFlightRef.current?.identity === requestedIdentity) {
        pendingRefreshRef.current = requestedIdentity;
        if (refresh) setRefreshing(true);
        return;
      }
      const generation = ++loadGenerationRef.current;
      refresh ? setRefreshing(true) : setLoading(true);
      setError('');
      try {
        const boundary = await captureAccountSessionBoundary();
        const nextSessions = await getDeviceSessions();
        assertAccountSessionBoundary(boundary);
        if (
          generation !== loadGenerationRef.current ||
          requestedIdentity !== accountIdentityRef.current
        )
          return;
        setSessions(nextSessions);
      } catch (requestError) {
        if (
          generation !== loadGenerationRef.current ||
          requestedIdentity !== accountIdentityRef.current
        )
          return;
        if (
          requestError instanceof Error &&
          requestError.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
        ) {
          setSessions([]);
          return;
        }
        setError('تعذّر تحميل الأجهزة الآن');
      } finally {
        if (
          generation === loadGenerationRef.current &&
          requestedIdentity === accountIdentityRef.current
        ) {
          setLoading(false);
          setRefreshing(false);
        }
      }
    },
    [],
  );

  useFocusEffect(
    useCallback(() => {
      screenActiveRef.current = true;
      if (mutationFlightRef.current?.identity !== accountIdentity) {
        setRemoving(null);
      }
      if (authenticated) {
        void load(false, accountIdentity);
      } else {
        loadGenerationRef.current += 1;
        setSessions([]);
        setError('');
        setLoading(false);
        setRefreshing(false);
      }
      return () => {
        screenActiveRef.current = false;
        screenVisitRef.current += 1;
        loadGenerationRef.current += 1;
        pendingRefreshRef.current = null;
      };
    }, [accountIdentity, authenticated, load]),
  );

  const beginRevocation = (id: string) => {
    const identity = accountIdentityRef.current;
    if (mutationFlightRef.current?.identity === identity) return null;
    const owner = {identity};
    mutationFlightRef.current = owner;
    // An interrupted pull-to-refresh still deserves one fresh result, but no
    // read may restore a session using a snapshot from inside revocation.
    pendingRefreshRef.current = refreshing ? identity : null;
    loadGenerationRef.current += 1;
    setLoading(false);
    setRefreshing(false);
    setRemoving(id);
    return owner;
  };

  const finishRevocation = (owner: {identity: string}) => {
    if (mutationFlightRef.current !== owner) return;
    mutationFlightRef.current = null;
    const refreshRequested = pendingRefreshRef.current === owner.identity;
    pendingRefreshRef.current = null;
    if (
      screenActiveRef.current &&
      owner.identity === accountIdentityRef.current
    ) {
      setRemoving(null);
      setRefreshing(false);
      if (refreshRequested) void load(true, owner.identity);
    }
  };

  const revoke = (session: DeviceSession) => {
    if (
      session.current ||
      removing ||
      mutationFlightRef.current?.identity === accountIdentityRef.current
    )
      return;
    const dialogIdentity = accountIdentityRef.current;
    const dialogVisit = screenVisitRef.current;
    Alert.alert(
      'تسجيل الخروج من الجهاز',
      'سيحتاج تسجيل الدخول من جديد على هذا الجهاز فقط',
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'تسجيل الخروج',
          style: 'destructive',
          onPress: async () => {
            if (
              !screenActiveRef.current ||
              dialogVisit !== screenVisitRef.current ||
              dialogIdentity !== accountIdentityRef.current
            )
              return;
            const owner = beginRevocation(session.id);
            if (!owner) return;
            const requestedIdentity = owner.identity;
            try {
              const boundary = await captureAccountSessionBoundary();
              assertAccountSessionBoundary(boundary);
              if (
                !screenActiveRef.current ||
                dialogVisit !== screenVisitRef.current ||
                dialogIdentity !== accountIdentityRef.current
              )
                return;
              await revokeDeviceSession(session.id);
              assertAccountSessionBoundary(boundary);
              if (
                screenActiveRef.current &&
                requestedIdentity === accountIdentityRef.current
              ) {
                setSessions(current =>
                  current.filter(item => item.id !== session.id),
                );
              }
            } catch (requestError) {
              if (
                screenActiveRef.current &&
                requestedIdentity === accountIdentityRef.current
              ) {
                if (
                  requestError instanceof Error &&
                  requestError.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
                ) {
                  setSessions([]);
                } else {
                  Alert.alert('لم يتم تسجيل الخروج', 'حاول مرة أخرى بعد قليل');
                }
              }
            } finally {
              finishRevocation(owner);
            }
          },
        },
      ],
    );
  };

  const revokeOthers = () => {
    if (
      removing ||
      mutationFlightRef.current?.identity === accountIdentityRef.current ||
      !sessions.some(session => !session.current)
    )
      return;
    const dialogIdentity = accountIdentityRef.current;
    const dialogVisit = screenVisitRef.current;
    Alert.alert(
      'تسجيل الخروج من الأجهزة الأخرى',
      'سيبقى هذا الجهاز مسجّلًا فقط',
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'تسجيل الخروج',
          style: 'destructive',
          onPress: async () => {
            if (
              !screenActiveRef.current ||
              dialogVisit !== screenVisitRef.current ||
              dialogIdentity !== accountIdentityRef.current
            )
              return;
            const owner = beginRevocation('all');
            if (!owner) return;
            const requestedIdentity = owner.identity;
            try {
              const boundary = await captureAccountSessionBoundary();
              assertAccountSessionBoundary(boundary);
              if (
                !screenActiveRef.current ||
                dialogVisit !== screenVisitRef.current ||
                dialogIdentity !== accountIdentityRef.current
              )
                return;
              await revokeOtherDeviceSessions();
              assertAccountSessionBoundary(boundary);
              if (
                screenActiveRef.current &&
                requestedIdentity === accountIdentityRef.current
              ) {
                setSessions(current =>
                  current.filter(session => session.current),
                );
              }
            } catch (requestError) {
              if (
                screenActiveRef.current &&
                requestedIdentity === accountIdentityRef.current
              ) {
                if (
                  requestError instanceof Error &&
                  requestError.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
                ) {
                  setSessions([]);
                } else {
                  Alert.alert('لم يتم تسجيل الخروج', 'حاول مرة أخرى بعد قليل');
                }
              }
            } finally {
              finishRevocation(owner);
            }
          },
        },
      ],
    );
  };

  if (!authenticated) {
    return (
      <Container noPadding>
        <Content noPadding>
          <ResponsiveFrame>
            <HeaderWithBack title="الأجهزة المسجّل عليها" />
            <StatusView
              actionLabel="تسجيل الدخول"
              description="سجّل الدخول لإدارة أجهزتك"
              onAction={() =>
                openGuestLogin(navigation, {name: 'DeviceSessions'})
              }
              state="empty"
              title="أجهزتك مرتبطة بحسابك"
            />
          </ResponsiveFrame>
        </Content>
      </Container>
    );
  }

  return (
    <Container noPadding>
      <Content
        noPadding
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            tintColor={Palette.primary}
            onRefresh={() => void load(true)}
          />
        }>
        <ResponsiveFrame>
          <HeaderWithBack title="الأجهزة المسجّل عليها" />
          <View
            style={[
              styles.content,
              {
                paddingBottom: Math.max(
                  Spacing.section,
                  insets.bottom + Spacing.xl,
                ),
              },
            ]}>
            <Text style={styles.intro}>أنهِ أي جلسة على جهاز لا تستخدمه</Text>

            {loading ? (
              <ActivityIndicator color={Palette.primary} size="large" />
            ) : error ? (
              <View style={styles.stateCard}>
                <Text style={styles.stateText}>{error}</Text>
                <Pressable
                  accessibilityLabel="إعادة تحميل الأجهزة المسجّل عليها"
                  accessibilityRole="button"
                  style={styles.retryButton}
                  onPress={() => void load()}>
                  <Text style={styles.retryText}>حاول مرة أخرى</Text>
                </Pressable>
              </View>
            ) : sessions.length === 0 ? (
              <View style={styles.stateCard}>
                <Text style={styles.stateText}>
                  ستظهر أجهزتك هنا بعد تسجيل الدخول عليها
                </Text>
              </View>
            ) : (
              sessions.map(session => (
                <View key={session.id} style={styles.sessionCard}>
                  <View style={styles.sessionHeader}>
                    <View
                      accessibilityElementsHidden
                      importantForAccessibility="no-hide-descendants"
                      style={styles.deviceIcon}>
                      <SessionDeviceIcon
                        deviceClass={deviceClassForSession(session)}
                      />
                    </View>
                    <View style={styles.sessionCopy}>
                      <View style={styles.sessionTitleRow}>
                        <Text style={styles.sessionTitle}>
                          {sessionLabel(session)}
                        </Text>
                        {session.current && (
                          <View style={styles.currentPill}>
                            <Text style={styles.currentText}>هذا الجهاز</Text>
                          </View>
                        )}
                      </View>
                      <Text style={styles.sessionMeta}>
                        آخر استخدام{' '}
                        {dateLabel(session.last_used_at || session.issued_at)}
                      </Text>
                    </View>
                  </View>
                  {!session.current && (
                    <Pressable
                      accessibilityRole="button"
                      disabled={Boolean(removing)}
                      onPress={() => revoke(session)}
                      style={({pressed}) => [
                        styles.logoutButton,
                        pressed && styles.pressed,
                      ]}>
                      {removing === session.id ? (
                        <ActivityIndicator color={Palette.danger} />
                      ) : (
                        <Text style={styles.logoutText}>
                          تسجيل الخروج من الجهاز
                        </Text>
                      )}
                    </Pressable>
                  )}
                </View>
              ))
            )}
            {sessions.some(session => !session.current) &&
              !loading &&
              !error && (
                <Pressable
                  accessibilityRole="button"
                  disabled={Boolean(removing)}
                  onPress={revokeOthers}
                  style={styles.logoutOthersButton}>
                  {removing === 'all' ? (
                    <ActivityIndicator color={Palette.danger} />
                  ) : (
                    <Text style={styles.logoutText}>
                      تسجيل الخروج من الأجهزة الأخرى
                    </Text>
                  )}
                </Pressable>
              )}
          </View>
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}

const styles = StyleSheet.create({
  content: {gap: Spacing.md},
  intro: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginBottom: Spacing.sm,
  },
  sessionCard: {
    paddingVertical: Spacing.lg,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: Palette.lineSoft,
  },
  sessionHeader: {...rtlRowStyle, alignItems: 'flex-start', gap: Spacing.md},
  deviceIcon: {
    width: 44,
    height: 44,
    flexShrink: 0,
    alignItems: 'center',
    justifyContent: 'center',
  },
  sessionCopy: {flex: 1, minWidth: 0},
  sessionTitleRow: {
    ...rtlRowStyle,
    flexWrap: 'wrap',
    alignItems: 'center',
    gap: Spacing.sm,
  },
  sessionTitle: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  sessionMeta: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  currentPill: {
    borderRadius: Radius.pill,
    backgroundColor: Palette.primarySoft,
    paddingHorizontal: Spacing.sm,
    paddingVertical: Spacing.xs,
  },
  currentText: {...Type.caption, color: Palette.primary},
  logoutButton: {
    alignItems: 'flex-start',
    marginStart: 44 + Spacing.md,
    marginTop: Spacing.xs,
    minHeight: 48,
    justifyContent: 'center',
  },
  logoutOthersButton: {
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 48,
    marginTop: Spacing.lg,
    paddingVertical: Spacing.sm,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.danger,
    paddingHorizontal: Spacing.md,
  },
  logoutText: {...Type.body, ...textDirection, color: Palette.danger},
  stateCard: {padding: Spacing.xl, alignItems: 'center', gap: Spacing.md},
  stateText: {...Type.body, ...textDirection, color: Palette.textMuted},
  retryButton: {
    minHeight: 48,
    justifyContent: 'center',
    paddingHorizontal: Spacing.lg,
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
  },
  retryText: {...Type.body, color: Palette.text},
  pressed: {opacity: 0.72},
});
