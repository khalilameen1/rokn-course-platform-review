import React, {useEffect, useState} from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  useWindowDimensions,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {
  Palette,
  Type,
  rtlRowStyle,
  textDirection,
} from '../constants/designSystem';
import {useReducedMotion} from '../hooks/useReducedMotion';
import {useCourseSubscriptionCheckout} from '../hooks/useCourseSubscriptionCheckout';
import type {CourseAccessPlan} from '../services/roknApi';
import type {
  CourseCheckoutMode,
  CourseCheckoutFeature,
} from '../services/api/courseCheckout';
import {CoinAmount} from './ui/RoknCoin';
import {formatArabicNumber} from '../constants/arabicFormatting';

export const subscriptionPlanName = (plan: CourseAccessPlan) =>
  ({basic: 'Basic', guided: 'Plus', mentor: 'Pro'}[plan.code] || plan.name);
export const subscriptionPlanSummary = (
  plan: CourseAccessPlan,
  hasProjects: boolean,
) => {
  if (
    plan.code === 'mentor' &&
    plan.projectFollowupEnabled &&
    hasProjects &&
    plan.projectsEnabled !== false
  )
    return 'تدريب أعمق وتطوير مشروعك';
  if (
    !plan.chatEnabled &&
    !plan.certificateEnabled &&
    !plan.projectReportEnabled &&
    !(hasProjects && plan.projectsEnabled !== false)
  )
    return 'مشاهدة فقط';
  return [
    plan.chatEnabled ? 'أسئلة' : 'مشاهدة',
    hasProjects && plan.projectsEnabled !== false ? 'مشاريع' : '',
    plan.certificateEnabled ? 'شهادة' : '',
  ]
    .filter(Boolean)
    .join(' و');
};
export const subscriptionPlanDetails = (
  plan: CourseAccessPlan,
  hasProjects: boolean,
  grant = false,
): Array<{text: string; available?: boolean}> => {
  if (grant || plan.code === 'basic') {
    return [
      {
        text: grant ? 'مشاهدة الكورس مجانًا' : 'مشاهدة الكورس كاملًا',
        available: true,
      },
      {text: 'غير متاح الشات مع مدرب رُكن للأسئلة', available: false},
      {text: 'لا توجد مشاريع عبور عملية ولا تقييم عليها', available: false},
      {text: 'بدون شهادة اجتياز للكورس', available: false},
    ];
  }
  return [
    ...(plan.chatEnabled
      ? [
          {
            text: `${formatArabicNumber(
              plan.chatMessageLimit,
            )} رسالة لمناقشة محتوى الكورس`,
          },
        ]
      : []),
    ...(hasProjects && plan.projectsEnabled !== false
      ? [
          {
            text: plan.projectReportEnabled
              ? 'تنفيذ مشاريع عملية والحصول على تقييم لتحسين مستواك'
              : 'تنفيذ مشاريع عبور عملية',
          },
        ]
      : []),
    ...(hasProjects &&
    plan.projectsEnabled !== false &&
    plan.projectFollowupEnabled
      ? [
          {
            text: `${formatArabicNumber(
              plan.projectFollowupMessageLimit || 0,
            )} رسالة لمناقشة المشاريع`,
          },
        ]
      : []),
    ...(plan.certificateEnabled ? [{text: 'شهادة بعد اجتياز الكورس'}] : []),
  ];
};

export type CourseSubscriptionSheetProps = {
  visible: boolean;
  courseId: string;
  courseTitle: string;
  courseRevision?: number;
  plans: CourseAccessPlan[];
  selectedPlan?: CourseAccessPlan;
  onSelectPlan: (plan: CourseAccessPlan) => void;
  onClose: () => void;
  onCompleted: () => void | Promise<void>;
  onStart?: () => void;
  hasProjects?: boolean;
  mode?: CourseCheckoutMode;
  requiredFeature?: CourseCheckoutFeature;
  success?: boolean;
  grantActivated?: boolean;
  accessCodeEntry?: (disabled: boolean) => React.ReactNode;
  externalBusy?: boolean;
  externalNotice?: string;
  embedded?: boolean;
};

/** A single sheet from choosing a tier through the store's native confirmation.
 * No package picker, fabricated cash conversion, or second enrollment consent. */
export default function CourseSubscriptionSheet({
  visible,
  courseId,
  courseTitle,
  courseRevision,
  plans,
  selectedPlan,
  onSelectPlan,
  onClose,
  onCompleted,
  onStart,
  hasProjects = false,
  mode = 'purchase',
  requiredFeature,
  success = false,
  grantActivated = false,
  accessCodeEntry,
  externalBusy = false,
  externalNotice = '',
  embedded = false,
}: CourseSubscriptionSheetProps) {
  const insets = useSafeAreaInsets();
  const {fontScale} = useWindowDimensions();
  // Keep the comparison horizontal on phones, including enlarged system text.
  // Very large text gets wider, scrollable choices instead of a tall stack.
  const minimumOptionWidth = 88 * Math.max(1, fontScale / 1.5);
  const reducedMotion = useReducedMotion();
  const [codeExpanded, setCodeExpanded] = useState(false);
  const checkout = useCourseSubscriptionCheckout({
    courseId,
    mode,
    planCode: selectedPlan?.code,
    courseRevision,
    requiredFeature,
    visible: visible && !success,
    onCompleted,
  });
  const {
    quote,
    coinPackage,
    loading,
    busy,
    pending,
    blockedByPreviousCheckout,
  } = checkout;
  const displayedPlan = pending
    ? plans.find(plan => plan.code === quote?.planCode)
    : selectedPlan;
  const locked = busy || pending || blockedByPreviousCheckout || externalBusy;
  const finished = success || quote?.status === 'completed';
  const grantFinished = finished && grantActivated;
  const grantEntryVisible =
    !finished &&
    mode === 'purchase' &&
    displayedPlan?.code === 'basic' &&
    !pending &&
    !blockedByPreviousCheckout &&
    Boolean(accessCodeEntry);
  const inlineCodeNotice =
    grantEntryVisible && codeExpanded ? externalNotice : '';
  const notice = checkout.notice || (inlineCodeNotice ? '' : externalNotice);
  const detailPlan = grantFinished
    ? plans.find(plan => plan.code === 'basic')
    : displayedPlan;
  const canPay = Boolean(
    blockedByPreviousCheckout ||
      (quote &&
        (quote.status !== 'quoted' || quote.deficit === 0 || coinPackage)),
  );
  useEffect(() => {
    setCodeExpanded(false);
  }, [selectedPlan?.code]);
  useEffect(() => {
    if (!visible) setCodeExpanded(false);
  }, [visible]);
  const content = (
    <View
      accessibilityViewIsModal={!embedded}
      style={[
        styles.sheet,
        !embedded && {
          paddingBottom: Math.max(insets.bottom, 16),
          paddingLeft: Math.max(18, insets.left + 12),
          paddingRight: Math.max(18, insets.right + 12),
        },
        embedded && styles.embedded,
      ]}>
      {!embedded && <View accessible={false} style={styles.handle} />}
      <View style={styles.heading}>
        <View style={styles.headingCopy}>
          <Text style={styles.title}>
            {grantFinished
              ? 'تم تفعيل المنحة'
              : finished
              ? 'أصبح الاشتراك لك'
              : mode === 'upgrade'
              ? 'ترقية الاشتراك'
              : 'اختر الاشتراك'}
          </Text>
          <Text numberOfLines={1} style={styles.context}>
            {courseTitle}
          </Text>
        </View>
        {!embedded && (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="إغلاق الاشتراكات"
            accessibilityState={{disabled: busy || externalBusy}}
            disabled={busy || externalBusy}
            onPress={onClose}
            style={styles.close}>
            <Text style={styles.closeText}>×</Text>
          </Pressable>
        )}
      </View>
      <ScrollView
        keyboardShouldPersistTaps="handled"
        automaticallyAdjustKeyboardInsets
        showsVerticalScrollIndicator={false}
        style={styles.scroll}
        contentContainerStyle={styles.content}>
        {!finished && (
          <>
            <ScrollView
              horizontal
              keyboardShouldPersistTaps="handled"
              accessibilityRole="radiogroup"
              accessibilityLabel="اختيارات الاشتراك"
              style={styles.optionScroll}
              contentContainerStyle={styles.options}>
              {plans.map(plan => {
                const selected = plan.code === displayedPlan?.code;
                const price =
                  mode === 'purchase'
                    ? plan.priceCoins
                    : selected && quote
                    ? quote.originalPrice
                    : undefined;
                return (
                  <Pressable
                    key={plan.code}
                    accessibilityRole="radio"
                    accessibilityLabel={`${subscriptionPlanName(
                      plan,
                    )} ${subscriptionPlanSummary(plan, hasProjects)}${
                      price !== undefined
                        ? ` ${formatArabicNumber(price)} من رصيد ركن`
                        : ''
                    }`}
                    accessibilityState={{checked: selected, disabled: locked}}
                    disabled={locked}
                    onPress={() => onSelectPlan(plan)}
                    style={({pressed}) => [
                      styles.option,
                      {minWidth: minimumOptionWidth},
                      selected && styles.selected,
                      pressed && styles.pressed,
                    ]}>
                    <View style={styles.optionCopy}>
                      <Text style={styles.name}>
                        {subscriptionPlanName(plan)}
                      </Text>
                    </View>
                    {price !== undefined ? (
                      <CoinAmount
                        value={price}
                        size={20}
                        textStyle={styles.coinText}
                        style={styles.coinAmount}
                      />
                    ) : (
                      <Text style={styles.description}>عرض السعر</Text>
                    )}
                  </Pressable>
                );
              })}
            </ScrollView>
          </>
        )}
        {detailPlan && (!finished || grantFinished) && (
          <View style={styles.planDetails}>
            {subscriptionPlanDetails(
              detailPlan,
              hasProjects,
              grantFinished,
            ).map(({text, available}) => (
              <View key={text} style={styles.featureRow}>
                <Text accessible={false} style={styles.featureMark}>
                  {available === undefined ? '—' : available ? '✓' : '×'}
                </Text>
                <Text style={styles.detail}>{text}</Text>
              </View>
            ))}
          </View>
        )}
        {!finished && (
          <>
            {grantEntryVisible && accessCodeEntry && (
              <>
                <Pressable
                  accessibilityRole="button"
                  accessibilityState={{
                    expanded: codeExpanded,
                    disabled: locked,
                  }}
                  disabled={locked}
                  onPress={() => setCodeExpanded(value => !value)}
                  style={styles.disclosure}>
                  <Text style={styles.detailAction}>كود منحة</Text>
                  <Text style={styles.detailAction}>
                    {codeExpanded ? 'إخفاء' : 'إضافة'}
                  </Text>
                </Pressable>
                {codeExpanded && (
                  <View style={styles.details}>
                    {accessCodeEntry(locked || loading)}
                    {!!inlineCodeNotice && (
                      <Text
                        accessibilityRole="alert"
                        accessibilityLiveRegion="polite"
                        style={styles.notice}>
                        {inlineCodeNotice}
                      </Text>
                    )}
                  </View>
                )}
              </>
            )}
            {quote && (
              <View style={styles.summary}>
                {mode === 'purchase' && quote.rewardCoins > 0 && (
                  <SummaryLine label="حصلت على خصم" value={quote.rewardCoins} />
                )}
                {coinPackage && (
                  <View style={styles.cashRow}>
                    <Text style={styles.cashLabel}>المطلوب دفعه</Text>
                    <Text style={styles.cashValue}>
                      {coinPackage.displayPrice ||
                        `${formatArabicNumber(coinPackage.price)} ج م`}
                    </Text>
                  </View>
                )}
              </View>
            )}
          </>
        )}
        {finished && !grantFinished && (
          <Text style={styles.success}>تقدر تبدأ الآن</Text>
        )}
        {notice ? (
          <Text
            accessibilityRole="alert"
            accessibilityLiveRegion="polite"
            style={styles.notice}>
            {notice}
          </Text>
        ) : null}
      </ScrollView>
      <View style={styles.footer}>
        {(pending || blockedByPreviousCheckout) && (
          <View>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={
                blockedByPreviousCheckout
                  ? 'إلغاء الطلب السابق'
                  : 'إلغاء طلب الاشتراك'
              }
              disabled={busy}
              onPress={() => void checkout.cancelPending()}
              style={styles.disclosure}>
              <Text style={styles.detailAction}>
                {blockedByPreviousCheckout
                  ? 'إلغاء الطلب السابق'
                  : 'إلغاء طلب الاشتراك'}
              </Text>
            </Pressable>
            <Text style={styles.note}>أي شحن تم دفعه يظل في رصيدك</Text>
          </View>
        )}
        <Pressable
          accessibilityRole="button"
          accessibilityState={{
            busy: busy || loading,
            disabled:
              busy ||
              loading ||
              externalBusy ||
              (!finished && !canPay && Boolean(quote)),
          }}
          disabled={
            busy ||
            loading ||
            externalBusy ||
            (!finished && !canPay && Boolean(quote))
          }
          onPress={
            finished
              ? onStart || onClose
              : !quote
              ? checkout.retry
              : () => void checkout.confirm()
          }
          style={({pressed}) => [
            styles.primary,
            pressed && styles.pressed,
            (busy ||
              loading ||
              externalBusy ||
              (!finished && !canPay && Boolean(quote))) &&
              styles.disabled,
          ]}>
          {busy || loading ? (
            <ActivityIndicator color={Palette.text} />
          ) : (
            <Text style={styles.primaryText}>
              {finished
                ? 'ابدأ الكورس'
                : blockedByPreviousCheckout
                ? 'التحقق من الدفع السابق'
                : pending
                ? 'التحقق من الدفع'
                : !quote
                ? 'إعادة المحاولة'
                : quote.status !== 'quoted'
                ? 'مراجعة الاشتراك'
                : mode === 'upgrade'
                ? 'ترقية الاشتراك'
                : 'اشترك'}
            </Text>
          )}
        </Pressable>
      </View>
    </View>
  );
  if (embedded) return content;
  return (
    <Modal
      visible={visible}
      transparent
      statusBarTranslucent
      animationType={reducedMotion ? 'none' : 'slide'}
      onRequestClose={() => {
        if (!busy && !externalBusy) onClose();
      }}>
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        style={styles.root}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="إغلاق"
          disabled={busy || externalBusy}
          onPress={onClose}
          style={styles.backdrop}
        />
        {content}
      </KeyboardAvoidingView>
    </Modal>
  );
}

const SummaryLine = ({label, value}: {label: string; value: number}) => (
  <View style={styles.summaryLine}>
    <Text style={styles.note}>{label}</Text>
    <CoinAmount value={value} size={16} textStyle={styles.summaryCoinText} />
  </View>
);
const styles = StyleSheet.create({
  root: {flex: 1, justifyContent: 'flex-end'},
  backdrop: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: Palette.overlay,
  },
  sheet: {
    width: '100%',
    maxWidth: 620,
    alignSelf: 'center',
    maxHeight: '92%',
    backgroundColor: Palette.surface,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
  },
  embedded: {maxHeight: '100%', padding: 18, flex: 1},
  handle: {
    width: 32,
    height: 4,
    borderRadius: 4,
    backgroundColor: Palette.line,
    alignSelf: 'center',
    marginVertical: 10,
  },
  heading: {...rtlRowStyle, alignItems: 'center', gap: 12, paddingBottom: 16},
  headingCopy: {flex: 1, minWidth: 0},
  title: {...Type.title, ...textDirection, color: Palette.text},
  context: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 3,
  },
  close: {
    height: 48,
    width: 48,
    alignItems: 'center',
    justifyContent: 'center',
  },
  closeText: {fontSize: 28, color: Palette.textMuted},
  scroll: {flexGrow: 0},
  content: {paddingBottom: 4},
  optionScroll: {flexGrow: 0},
  options: {...rtlRowStyle, gap: 8, flexGrow: 1},
  option: {
    flexGrow: 1,
    flexShrink: 0,
    flexBasis: 0,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 10,
    paddingHorizontal: 6,
    paddingVertical: 14,
    minHeight: 88,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: Palette.line,
    backgroundColor: Palette.surface,
  },
  selected: {
    borderColor: Palette.primary,
    backgroundColor: Palette.primarySoft,
  },
  optionCopy: {minWidth: 0},
  name: {...Type.section, color: Palette.text, textAlign: 'center'},
  description: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 3,
  },
  coinAmount: {alignSelf: 'center'},
  coinText: {...Type.section, color: Palette.text},
  disclosure: {
    ...rtlRowStyle,
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
  },
  detailAction: {...Type.caption, ...textDirection, color: Palette.textMuted},
  details: {gap: 8, paddingBottom: 14},
  planDetails: {gap: 8, paddingTop: 18, paddingBottom: 10},
  featureRow: {...rtlRowStyle, alignItems: 'flex-start', gap: 9},
  featureMark: {
    ...Type.caption,
    color: Palette.textMuted,
    width: 14,
    textAlign: 'center',
  },
  detail: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    flex: 1,
    minWidth: 0,
  },
  summary: {
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Palette.line,
    paddingTop: 12,
    gap: 9,
  },
  summaryLine: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
  },
  note: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    flexShrink: 1,
  },
  summaryCoinText: {...Type.caption, color: Palette.textMuted},
  cashRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
  },
  cashLabel: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  cashValue: {...Type.section, color: Palette.text},
  footer: {
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Palette.line,
    paddingTop: 14,
    marginTop: 8,
  },
  primary: {
    minHeight: 52,
    paddingHorizontal: 16,
    paddingVertical: 12,
    borderRadius: 14,
    backgroundColor: Palette.primary,
    justifyContent: 'center',
    alignItems: 'center',
  },
  primaryText: {...Type.button, color: Palette.text},
  disabled: {opacity: 0.55},
  pressed: {opacity: 0.86},
  notice: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginVertical: 10,
  },
  success: {
    ...Type.body,
    ...textDirection,
    color: Palette.text,
    marginVertical: 20,
  },
});
