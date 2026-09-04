import {useFocusEffect, useNavigation} from '@react-navigation/native';
import React, {useCallback, useRef, useState} from 'react';
import {
  ActivityIndicator,
  Alert,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {useSelector} from 'react-redux';
import Svg, {Circle, Rect} from 'react-native-svg';
import {Container, Content} from '../components/containers/Containers';
import {
  PremiumCard,
  ResponsiveFrame,
  StatusView,
} from '../components/ui/PremiumUI';
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
  return formatRoknDate(value, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }) || 'غير معروف';
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
      <Circle
        cx={13}
        cy={tablet ? 19.5 : 20.5}
        fill={Palette.primary}
        r={1}
      />
    </Svg>
  );
};

export default function DeviceSessions() {
  const navigation = useNavigation<RootNavigation>();
  const insets = useSafeAreaInsets();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const authenticated = Boolean(extractApiToken(storedUser));
  const [sessions, setSessions] = useState<DeviceSession[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [removing, setRemoving] = useState<string | null>(null);
  const [error, setError] = useState('');
  const loadGenerationRef = useRef(0);
  const mutationFlightRef = useRef(false);
  const screenActiveRef = useRef(false);

  const load = useCallback(async (refresh = false) => {
    const generation = ++loadGenerationRef.current;
    refresh ? setRefreshing(true) : setLoading(true);
    setError('');
    try {
      const boundary = await captureAccountSessionBoundary();
      const nextSessions = await getDeviceSessions();
      assertAccountSessionBoundary(boundary);
      if (generation !== loadGenerationRef.current) return;
      setSessions(nextSessions);
    } catch (requestError) {
      if (generation !== loadGenerationRef.current) return;
      if (
        requestError instanceof Error &&
        requestError.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      ) {
        setSessions([]);
        return;
      }
      setError('تعذّر تحميل الأجهزة الآن');
    } finally {
      if (generation === loadGenerationRef.current) {
        setLoading(false);
        setRefreshing(false);
      }
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      screenActiveRef.current = true;
      if (!mutationFlightRef.current) setRemoving(null);
      if (authenticated) {
        void load();
      } else {
        loadGenerationRef.current += 1;
        setSessions([]);
        setError('');
        setLoading(false);
        setRefreshing(false);
      }
      return () => {
        screenActiveRef.current = false;
        loadGenerationRef.current += 1;
      };
    }, [authenticated, load]),
  );

  const revoke = (session: DeviceSession) => {
    if (session.current || removing || mutationFlightRef.current) return;
    Alert.alert(
      'تسجيل الخروج من الجهاز',
      'سيحتاج تسجيل الدخول من جديد على هذا الجهاز فقط',
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'تسجيل الخروج',
          style: 'destructive',
          onPress: async () => {
            if (mutationFlightRef.current) return;
            mutationFlightRef.current = true;
            loadGenerationRef.current += 1;
            setRemoving(session.id);
            try {
              const boundary = await captureAccountSessionBoundary();
              await revokeDeviceSession(session.id);
              assertAccountSessionBoundary(boundary);
              if (screenActiveRef.current) {
                setSessions(current =>
                  current.filter(item => item.id !== session.id),
                );
              }
            } catch (requestError) {
              if (screenActiveRef.current) {
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
              mutationFlightRef.current = false;
              if (screenActiveRef.current) setRemoving(null);
            }
          },
        },
      ],
    );
  };

  const revokeOthers = () => {
    if (
      removing ||
      mutationFlightRef.current ||
      !sessions.some(session => !session.current)
    )
      return;
    Alert.alert(
      'تسجيل الخروج من الأجهزة الأخرى',
      'سيبقى هذا الجهاز مسجّلًا فقط',
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'تسجيل الخروج',
          style: 'destructive',
          onPress: async () => {
            if (mutationFlightRef.current) return;
            mutationFlightRef.current = true;
            loadGenerationRef.current += 1;
            setRemoving('all');
            try {
              const boundary = await captureAccountSessionBoundary();
              await revokeOtherDeviceSessions();
              assertAccountSessionBoundary(boundary);
              if (screenActiveRef.current) {
                setSessions(current =>
                  current.filter(session => session.current),
                );
              }
            } catch (requestError) {
              if (screenActiveRef.current) {
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
              mutationFlightRef.current = false;
              if (screenActiveRef.current) setRemoving(null);
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
      <Content noPadding>
        <ResponsiveFrame>
          <HeaderWithBack title="الأجهزة المسجّل عليها" />
          <ScrollView
            contentContainerStyle={[
              styles.content,
              {
                paddingBottom: Math.max(
                  Spacing.section,
                  insets.bottom + Spacing.xl,
                ),
              },
            ]}
            refreshControl={
              <RefreshControl
                refreshing={refreshing}
                tintColor={Palette.primary}
                onRefresh={() => void load(true)}
              />
            }>
            <Text style={styles.intro}>
              أنهِ أي جلسة على جهاز لا تستخدمه
            </Text>

            {sessions.some(session => !session.current) && !loading && !error && (
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

            {loading ? (
              <ActivityIndicator color={Palette.primary} size="large" />
            ) : error ? (
              <PremiumCard style={styles.stateCard}>
                <Text style={styles.stateText}>{error}</Text>
                <Pressable
                  accessibilityLabel="إعادة تحميل الأجهزة المسجّل عليها"
                  accessibilityRole="button"
                  style={styles.retryButton}
                  onPress={() => void load()}>
                  <Text style={styles.retryText}>حاول مرة أخرى</Text>
                </Pressable>
              </PremiumCard>
            ) : sessions.length === 0 ? (
              <PremiumCard style={styles.stateCard}>
                <Text style={styles.stateText}>
                  ستظهر أجهزتك هنا بعد تسجيل الدخول عليها
                </Text>
              </PremiumCard>
            ) : (
              sessions.map(session => (
                <PremiumCard key={session.id} style={styles.sessionCard}>
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
                      <Text style={styles.sessionTitle}>
                        {sessionLabel(session)}
                      </Text>
                      <Text style={styles.sessionMeta}>
                        آخر استخدام{' '}
                        {dateLabel(session.last_used_at || session.issued_at)}
                      </Text>
                    </View>
                    {session.current && (
                      <View style={styles.currentPill}>
                        <Text style={styles.currentText}>هذا الجهاز</Text>
                      </View>
                    )}
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
                </PremiumCard>
              ))
            )}
          </ScrollView>
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}

const styles = StyleSheet.create({
  content: {paddingHorizontal: Spacing.lg, gap: Spacing.md},
  intro: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginBottom: Spacing.sm,
  },
  sessionCard: {padding: Spacing.lg, borderRadius: Radius.lg},
  sessionHeader: {...rtlRowStyle, alignItems: 'flex-start', gap: Spacing.md},
  deviceIcon: {
    width: 44,
    height: 44,
    flexShrink: 0,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.md,
    backgroundColor: Palette.primarySoft,
  },
  sessionCopy: {flex: 1, minWidth: 0},
  sessionTitle: {...Type.section, ...textDirection, color: Palette.text},
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
    alignItems: 'center',
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Palette.line,
    marginTop: Spacing.md,
    paddingTop: Spacing.md,
    minHeight: 44,
    justifyContent: 'center',
  },
  logoutOthersButton: {
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 48,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.danger,
    paddingHorizontal: Spacing.md,
  },
  logoutText: {...Type.body, color: Palette.danger},
  stateCard: {padding: Spacing.xl, alignItems: 'center', gap: Spacing.md},
  stateText: {...Type.body, ...textDirection, color: Palette.textMuted},
  retryButton: {
    minHeight: 44,
    justifyContent: 'center',
    paddingHorizontal: Spacing.lg,
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
  },
  retryText: {...Type.body, color: Palette.text},
  pressed: {opacity: 0.72},
});
