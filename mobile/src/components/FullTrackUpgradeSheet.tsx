import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../navigation/types';
import {errorCode} from '../utils/errorPayload';
import React, {useCallback, useEffect, useRef, useState} from 'react';
import {
  ActivityIndicator,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {
  CourseChatUpgradeQuote,
  getFullTrackUpgradeQuote,
  purchaseFullTrackUpgrade,
} from '../services/roknApi';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../constants/designSystem';
import {Fonts} from '../constants/styleConstants';
import {CoinAmount} from './ui/RoknCoin';
import {useReducedMotion} from '../hooks/useReducedMotion';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../constants/helpers';

type Props = {
  visible: boolean;
  courseId: string;
  courseTitle: string;
  completed?: boolean;
  onClose: () => void;
  onUpgraded?: () => void | Promise<void>;
};

export default function FullTrackUpgradeSheet({
  visible,
  courseId,
  courseTitle,
  completed = false,
  onClose,
  onUpgraded,
}: Props) {
  const navigation = useNavigation<RootNavigation>();
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  const [quote, setQuote] = useState<CourseChatUpgradeQuote | null>(null);
  const [loading, setLoading] = useState(false);
  const [purchasing, setPurchasing] = useState(false);
  const [error, setError] = useState('');
  const activeCourseIdRef = useRef(courseId);
  const operationFlightRef = useRef<symbol | null>(null);
  const onCloseRef = useRef(onClose);
  const onUpgradedRef = useRef(onUpgraded);
  activeCourseIdRef.current = courseId;
  onCloseRef.current = onClose;
  onUpgradedRef.current = onUpgraded;

  const load = useCallback(async () => {
    if (!courseId) return;
    const flight = Symbol('full-track-upgrade-quote');
    operationFlightRef.current = flight;
    setLoading(true);
    setError('');
    try {
      const boundary = await captureAccountSessionBoundary();
      const next = await getFullTrackUpgradeQuote(courseId);
      assertAccountSessionBoundary(boundary);
      if (
        operationFlightRef.current !== flight ||
        activeCourseIdRef.current !== courseId
      )
        return;
      if (next.alreadyUpgraded || next.certificateAvailable) {
        await onUpgradedRef.current?.();
        assertAccountSessionBoundary(boundary);
        if (
          operationFlightRef.current !== flight ||
          activeCourseIdRef.current !== courseId
        )
          return;
        onCloseRef.current();
        return;
      }
      setQuote(next);
    } catch (requestError: unknown) {
      if (
        operationFlightRef.current !== flight ||
        activeCourseIdRef.current !== courseId
      )
        return;
      if (
        requestError instanceof Error &&
        requestError.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      ) {
        setQuote(null);
        onCloseRef.current();
        return;
      }
      const code = errorCode(requestError);
      setError(
        code.includes('not_priced')
          ? 'خيار الدعم غير مسعّر لهذا الكورس الآن'
          : 'تعذّر تحميل التفاصيل\nحاول مرة أخرى',
      );
    } finally {
      if (operationFlightRef.current === flight) {
        operationFlightRef.current = null;
        setLoading(false);
      }
    }
  }, [courseId]);

  useEffect(() => {
    operationFlightRef.current = null;
    setPurchasing(false);
    if (visible) {
      setQuote(null);
      setError('');
      void load();
    } else {
      setLoading(false);
    }
    return () => {
      operationFlightRef.current = null;
    };
  }, [visible, load]);

  const continueToFullTrack = async () => {
    if (!quote || loading || operationFlightRef.current) return;
    if (quote.deficit > 0) {
      onCloseRef.current();
      navigation.navigate('Wallet', {
        returnTo: {
          name: 'CourseDetails',
          params: {
            courseId,
            openFullTrackUpgrade: true,
          },
        },
      });
      return;
    }
    const flight = Symbol('full-track-upgrade-purchase');
    operationFlightRef.current = flight;
    setLoading(true);
    setPurchasing(true);
    setError('');
    let boundary: Awaited<
      ReturnType<typeof captureAccountSessionBoundary>
    > | null = null;
    try {
      boundary = await captureAccountSessionBoundary();
      const result = await purchaseFullTrackUpgrade(
        courseId,
        quote.targetPlanCode,
        quote.price,
        quote.courseRevision,
      );
      assertAccountSessionBoundary(boundary);
      if (
        operationFlightRef.current !== flight ||
        activeCourseIdRef.current !== courseId
      )
        return;
      if (result.certificateAvailable || result.alreadyUpgraded) {
        await onUpgradedRef.current?.();
        assertAccountSessionBoundary(boundary);
        if (
          operationFlightRef.current !== flight ||
          activeCourseIdRef.current !== courseId
        )
          return;
        onCloseRef.current();
        return;
      }
      setQuote(result);
    } catch (requestError: unknown) {
      if (
        operationFlightRef.current !== flight ||
        activeCourseIdRef.current !== courseId
      )
        return;
      if (
        !boundary ||
        (requestError instanceof Error &&
          requestError.message === 'ACCOUNT_CHANGED_DURING_REQUEST')
      ) {
        setQuote(null);
        onCloseRef.current();
        return;
      }
      const code = errorCode(requestError);
      const reconciledError =
        code === 'course_price_changed' || code === 'course_terms_changed'
          ? 'تغيرت تفاصيل الفئة\nراجعها قبل الشراء'
          : code === 'insufficient_coins'
          ? 'تغير رصيدك\nراجع المبلغ المتبقي وحاول مرة أخرى'
          : 'تعذّر تأكيد النتيجة\nنتحقق منها الآن';
      setError(reconciledError);
      try {
        const refreshedQuote = await getFullTrackUpgradeQuote(courseId);
        assertAccountSessionBoundary(boundary);
        if (
          operationFlightRef.current !== flight ||
          activeCourseIdRef.current !== courseId
        )
          return;
        if (
          refreshedQuote.alreadyUpgraded ||
          refreshedQuote.certificateAvailable
        ) {
          setError('');
          await onUpgradedRef.current?.();
          assertAccountSessionBoundary(boundary);
          if (
            operationFlightRef.current === flight &&
            activeCourseIdRef.current === courseId
          ) {
            onCloseRef.current();
          }
          return;
        }
        setQuote(refreshedQuote);
        setError(
          code === 'course_price_changed'
            ? 'تغير السعر\nراجع الإجمالي قبل الشراء'
            : code === 'insufficient_coins'
            ? 'تغير رصيدك\nراجع المبلغ المتبقي وحاول مرة أخرى'
            : 'لم تكتمل العملية\nحاول مرة أخرى',
        );
      } catch {
        if (
          operationFlightRef.current === flight &&
          activeCourseIdRef.current === courseId
        ) {
          setError('تعذّر تأكيد النتيجة\nحاول مرة أخرى');
        }
      }
    } finally {
      if (operationFlightRef.current === flight) {
        operationFlightRef.current = null;
        setLoading(false);
        setPurchasing(false);
      }
    }
  };

  const requestClose = () => {
    if (!purchasing) onCloseRef.current();
  };

  return (
    <Modal
      animationType={reducedMotion ? 'none' : 'slide'}
      onRequestClose={requestClose}
      statusBarTranslucent
      transparent
      visible={visible}>
      <View style={styles.root}>
        <Pressable
          accessibilityLabel="إغلاق"
          accessibilityRole="button"
          accessibilityState={{disabled: purchasing}}
          disabled={purchasing}
          onPress={requestClose}
          style={styles.backdrop}
        />
        <View
          accessibilityLabel="خيارات دعم الكورس"
          accessibilityViewIsModal
          style={[
            styles.sheet,
            {
              paddingBottom: Math.max(insets.bottom, Spacing.md),
              paddingLeft: Math.max(0, insets.left),
              paddingRight: Math.max(0, insets.right),
            },
          ]}>
          <View
            accessible={false}
            importantForAccessibility="no"
            style={styles.handle}
          />
          <ScrollView
            contentContainerStyle={styles.content}
            showsVerticalScrollIndicator={false}>
            <View style={styles.giftPill}>
              <Text style={styles.giftPillText}>منحتك مستمرة كما هي</Text>
            </View>
            <Text style={styles.title}>الكورس كامل متاح لك مجانًا</Text>
            <Text numberOfLines={2} style={styles.courseTitle}>
              {courseTitle}
            </Text>

            <View style={styles.includedCard}>
              <Text style={styles.includedTitle}>
                {quote?.targetPlanName || 'الترقية'} تشمل
              </Text>
              {quote?.aiIncluded !== false && (
                <Text style={styles.includedLine}>
                  استفسارات عن الكورس عند الحاجة
                </Text>
              )}
              <Text style={styles.includedLine}>
                شهادة إتمام يمكنك تحميلها ومشاركتها
              </Text>
              {completed && (
                <Text style={styles.completedLine}>
                  أنهيت الكورس ويمكنك إصدار الشهادة بعد الترقية
                </Text>
              )}
            </View>

            {quote && (
              <View style={styles.priceCard}>
                <View style={styles.priceRow}>
                  <Text style={styles.priceLabel}>
                    {quote.targetPlanName || 'الاختيار المدفوع'}
                  </Text>
                  <CoinAmount size={20} value={quote.price} />
                </View>
                <View style={styles.divider} />
                <View style={styles.priceRow}>
                  <Text style={styles.balanceLabel}>المتاح من رصيدك</Text>
                  <CoinAmount size={17} value={quote.spendableBalance} />
                </View>
                {quote.deficit > 0 && (
                  <Text style={styles.deficit}>
                    ينقصك {new Intl.NumberFormat('ar-EG').format(quote.deficit)}{' '}
                    من رصيد ركن
                  </Text>
                )}
              </View>
            )}

            {!!error && (
              <Text accessibilityRole="alert" style={styles.error}>
                {error}
              </Text>
            )}

            <Pressable
              accessibilityRole="button"
              disabled={loading || !quote}
              onPress={() => void continueToFullTrack()}
              style={({pressed}) => [
                styles.primary,
                pressed && styles.pressed,
                (loading || !quote) && styles.disabled,
              ]}>
              {loading ? (
                <ActivityIndicator color="#FFFFFF" />
              ) : (
                <Text style={styles.primaryText}>
                  {quote?.deficit
                    ? 'شحن الرصيد الناقص'
                    : `الانتقال إلى ${
                        quote?.targetPlanName || 'الاختيار المدفوع'
                      }`}
                </Text>
              )}
            </Pressable>
            <Pressable
              accessibilityRole="button"
              accessibilityState={{disabled: purchasing}}
              disabled={purchasing}
              onPress={requestClose}
              style={[styles.secondary, purchasing && styles.disabled]}>
              <Text style={styles.secondaryText}>أكمل الكورس مجانًا</Text>
            </Pressable>
            <Text style={styles.reassurance}>
              إغلاق النافذة لا يؤثر على منحتك أو تقدمك
            </Text>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  root: {flex: 1, justifyContent: 'flex-end'},
  backdrop: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: Palette.overlay,
  },
  sheet: {
    width: '100%',
    maxWidth: 680,
    alignSelf: 'center',
    maxHeight: '88%',
    backgroundColor: Palette.surface,
    borderTopLeftRadius: Radius.xl,
    borderTopRightRadius: Radius.xl,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
  },
  handle: {
    width: 42,
    height: 4,
    borderRadius: 2,
    backgroundColor: Palette.line,
    alignSelf: 'center',
    marginTop: 10,
  },
  content: {padding: Spacing.lg, paddingTop: Spacing.md},
  giftPill: {
    alignSelf: 'flex-start',
    paddingHorizontal: 11,
    paddingVertical: 6,
    borderRadius: Radius.pill,
    backgroundColor: 'rgba(72,185,138,.12)',
  },
  giftPillText: {
    ...Type.caption,
    color: Palette.success,
    fontFamily: Fonts.semiBold,
  },
  title: {...Type.title, ...textDirection, color: Palette.text, marginTop: 12},
  courseTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    color: '#8BB5FF',
    marginTop: 4,
  },
  description: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 4,
  },
  includedCard: {
    marginTop: Spacing.lg,
    borderRadius: Radius.lg,
    padding: Spacing.md,
    backgroundColor: Palette.canvasSoft,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    gap: 8,
  },
  includedTitle: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  includedLine: {...Type.body, ...textDirection, color: Palette.textMuted},
  completedLine: {
    ...Type.caption,
    ...textDirection,
    color: Palette.success,
    marginTop: 2,
  },
  priceCard: {
    marginTop: Spacing.md,
    borderRadius: Radius.lg,
    padding: Spacing.md,
    backgroundColor: Palette.coinSoft,
    borderWidth: 1,
    borderColor: 'rgba(216,166,60,.22)',
  },
  priceRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  priceLabel: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  balanceLabel: {...Type.caption, ...textDirection, color: Palette.textMuted},
  divider: {
    height: StyleSheet.hairlineWidth,
    backgroundColor: 'rgba(216,166,60,.18)',
    marginVertical: 12,
  },
  deficit: {...Type.caption, ...textDirection, color: '#E5BD67', marginTop: 8},
  error: {
    ...Type.caption,
    ...textDirection,
    color: Palette.danger,
    marginTop: Spacing.sm,
  },
  primary: {
    minHeight: 54,
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: Spacing.lg,
    paddingHorizontal: Spacing.md,
  },
  primaryText: {...Type.button, color: '#FFFFFF'},
  secondary: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 6,
  },
  secondaryText: {...Type.bodyStrong, color: Palette.textMuted},
  reassurance: {
    ...Type.caption,
    ...textDirection,
    textAlign: 'center',
    color: Palette.textFaint,
    marginTop: 2,
  },
  pressed: {opacity: 0.86},
  disabled: {opacity: 0.55},
});
