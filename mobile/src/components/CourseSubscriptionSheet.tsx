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
  TextInput,
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
import type {CourseCheckoutMode} from '../services/api/courseCheckout';
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
) => [
  'مشاهدة الكورس كاملًا',
  ...(plan.chatEnabled
    ? [`حتى ${formatArabicNumber(plan.chatMessageLimit)} رسالة للأسئلة`]
    : []),
  ...(hasProjects && plan.projectsEnabled !== false
    ? [plan.projectReportEnabled ? 'مشاريع وتقارير على شغلك' : 'مشاريع عبور']
    : []),
  ...(hasProjects && plan.projectFollowupEnabled
    ? [
        `حتى ${formatArabicNumber(
          plan.projectFollowupMessageLimit || 0,
        )} رسالة لمناقشة المشاريع`,
      ]
    : []),
  ...(plan.projectOutputEnabled ? ['تطوير مخرجات مشروعك'] : []),
  ...(plan.certificateEnabled ? ['شهادة بعد اجتياز متطلبات الكورس'] : []),
];

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
  success?: boolean;
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
  success = false,
  accessCodeEntry,
  externalBusy = false,
  externalNotice = '',
  embedded = false,
}: CourseSubscriptionSheetProps) {
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  const [expanded, setExpanded] = useState(false);
  const [codeExpanded, setCodeExpanded] = useState(false);
  const checkout = useCourseSubscriptionCheckout({
    courseId,
    mode,
    planCode: selectedPlan?.code,
    courseRevision,
    visible: visible && !success,
    onCompleted,
  });
  const {quote, coinPackage, loading, busy, pending} = checkout;
  const displayedPlan = pending
    ? plans.find(plan => plan.code === quote?.planCode)
    : selectedPlan;
  const locked = busy || pending || externalBusy;
  const finished = success || quote?.status === 'completed';
  const canPay = Boolean(
    quote && (quote.status !== 'quoted' || quote.deficit === 0 || coinPackage),
  );
  const remainingPaid = quote?.remainingPaidCoins ?? 0;
  useEffect(() => {
    setExpanded(false);
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
            {finished
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
            <View accessibilityRole="radiogroup" style={styles.options}>
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
                      selected && styles.selected,
                      pressed && styles.pressed,
                    ]}>
                    <View
                      style={[styles.radio, selected && styles.radioSelected]}>
                      {selected && <View style={styles.radioDot} />}
                    </View>
                    <View style={styles.optionCopy}>
                      <Text style={styles.name}>
                        {subscriptionPlanName(plan)}
                      </Text>
                      <Text style={styles.description}>
                        {subscriptionPlanSummary(plan, hasProjects)}
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
            </View>
            {!!displayedPlan && (
              <>
                <Pressable
                  accessibilityRole="button"
                  accessibilityState={{expanded}}
                  accessibilityLabel={`تفاصيل ${subscriptionPlanName(
                    displayedPlan,
                  )}`}
                  onPress={() => setExpanded(value => !value)}
                  style={styles.disclosure}>
                  <Text style={styles.detailTitle}>
                    تفاصيل {subscriptionPlanName(displayedPlan)}
                  </Text>
                  <Text style={styles.detailAction}>
                    {expanded ? 'إخفاء' : 'عرض'}
                  </Text>
                </Pressable>
                {expanded && (
                  <View style={styles.details}>
                    {subscriptionPlanDetails(displayedPlan, hasProjects).map(
                      text => (
                        <Text key={text} style={styles.detail}>
                          {text}
                        </Text>
                      ),
                    )}
                  </View>
                )}
              </>
            )}
            {quote && (
              <View style={styles.summary}>
                {!!checkout.rewardCashSaving && (
                  <Text style={styles.note}>
                    وفرت {checkout.rewardCashSaving} بمكافآتك
                  </Text>
                )}
                {quote.discountAmount > 0 && (
                  <SummaryLine label="خصم الكود" value={quote.discountAmount} />
                )}
                {quote.rewardCoins > 0 && (
                  <SummaryLine
                    label="مكافآت مستخدمة"
                    value={quote.rewardCoins}
                  />
                )}
                {Math.min(quote.paidBalance, quote.paidCoins) > 0 && (
                  <SummaryLine
                    label="من رصيدك المشترى"
                    value={Math.min(quote.paidBalance, quote.paidCoins)}
                  />
                )}
                {quote.rewardBalance > 0 && quote.rewardCoins === 0 && (
                  <Text style={styles.note}>مكافآتك المتبقية محفوظة</Text>
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
                {coinPackage && remainingPaid > 0 && (
                  <SummaryLine label="يتبقى رصيد مشترى" value={remainingPaid} />
                )}
              </View>
            )}
            {mode === 'purchase' && !pending && (
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
                  <Text style={styles.detailAction}>معاك كود</Text>
                  <Text style={styles.detailAction}>
                    {codeExpanded ? 'إخفاء' : 'إضافة'}
                  </Text>
                </Pressable>
                {codeExpanded && (
                  <View style={styles.details}>
                    <View style={styles.codeRow}>
                      <TextInput
                        accessibilityLabel="كود خصم الكورس"
                        editable={!locked && !loading}
                        value={checkout.coupon}
                        onChangeText={checkout.setCoupon}
                        onSubmitEditing={checkout.applyCoupon}
                        autoCapitalize="characters"
                        autoCorrect={false}
                        maxLength={50}
                        placeholder="كود الخصم"
                        placeholderTextColor={Palette.textFaint}
                        style={styles.codeInput}
                      />
                      <Pressable
                        accessibilityRole="button"
                        disabled={locked || loading || !checkout.coupon.trim()}
                        onPress={checkout.applyCoupon}
                        style={styles.codeApply}>
                        <Text style={styles.detailTitle}>تطبيق</Text>
                      </Pressable>
                    </View>
                    {accessCodeEntry?.(locked || loading)}
                  </View>
                )}
              </>
            )}
          </>
        )}
        {finished && <Text style={styles.success}>تقدر تبدأ الآن</Text>}
        {checkout.notice || externalNotice ? (
          <Text
            accessibilityRole="alert"
            accessibilityLiveRegion="polite"
            style={styles.notice}>
            {checkout.notice || externalNotice}
          </Text>
        ) : null}
      </ScrollView>
      <View style={styles.footer}>
        {pending && (
          <View>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="إلغاء طلب الاشتراك"
              disabled={busy}
              onPress={() => void checkout.cancelPending()}
              style={styles.disclosure}>
              <Text style={styles.detailAction}>إلغاء طلب الاشتراك</Text>
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
                : pending
                ? 'التحقق من الدفع'
                : !quote
                ? 'إعادة المحاولة'
                : quote.status !== 'quoted'
                ? 'مراجعة الاشتراك'
                : quote.deficit > 0
                ? mode === 'upgrade'
                  ? 'شحن وترقية'
                  : 'شحن واشتراك'
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
  options: {gap: 10},
  option: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 11,
    paddingHorizontal: 14,
    paddingVertical: 15,
    minHeight: 91,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: Palette.line,
    backgroundColor: Palette.surface,
  },
  selected: {
    borderColor: Palette.primary,
    backgroundColor: Palette.primarySoft,
  },
  radio: {
    width: 18,
    height: 18,
    borderRadius: 9,
    borderWidth: 1.5,
    borderColor: Palette.textMuted,
    alignItems: 'center',
    justifyContent: 'center',
  },
  radioSelected: {borderColor: Palette.primary},
  radioDot: {
    width: 9,
    height: 9,
    borderRadius: 5,
    backgroundColor: Palette.primary,
  },
  optionCopy: {flex: 1, minWidth: 0},
  name: {...Type.section, ...textDirection, color: Palette.text},
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
  detailTitle: {...Type.caption, ...textDirection, color: Palette.text},
  detailAction: {...Type.caption, ...textDirection, color: Palette.textMuted},
  details: {gap: 8, paddingBottom: 14},
  detail: {...Type.caption, ...textDirection, color: Palette.textMuted},
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
  codeRow: {...rtlRowStyle, alignItems: 'center', gap: 8},
  codeInput: {
    ...Type.body,
    ...textDirection,
    flex: 1,
    color: Palette.text,
    minHeight: 48,
    borderWidth: 1,
    borderColor: Palette.line,
    borderRadius: 10,
    paddingHorizontal: 12,
  },
  codeApply: {
    minHeight: 48,
    minWidth: 64,
    justifyContent: 'center',
    alignItems: 'center',
  },
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
