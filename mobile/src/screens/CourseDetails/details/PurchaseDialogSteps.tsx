import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  Text,
  TextInput,
  View,
} from 'react-native';
import {CoinAmount} from '../../../components/ui/RoknCoin';
import {
  formatArabicDisplayText,
  formatArabicNumber,
} from '../../../constants/arabicFormatting';
import {Palette} from '../../../constants/designSystem';
import type {CoinPackage} from '../../../services/api/coinPackageMapper';
import type {CourseAccessPlan} from '../../../services/roknApi';
import {planBenefits} from './selectors';
import styles from './styles';

type CourseCodeEntryProps = {
  busy: boolean;
  code: string;
  onChange: (value: string) => void;
  onRedeem: () => void | Promise<void>;
};

export const CourseCodeEntry = ({
  busy,
  code,
  onChange,
  onRedeem,
}: CourseCodeEntryProps) => (
  <View style={styles.codeBox}>
    <Text style={styles.codeTitle}>كود جهة تعليمية</Text>
    <View style={styles.codeRow}>
      <TextInput
        accessibilityHint="أدخل الكود الذي استلمته من الجهة التعليمية"
        accessibilityLabel="كود الوصول إلى الكورس"
        autoCapitalize="characters"
        autoCorrect={false}
        editable={!busy}
        maxLength={50}
        onChangeText={onChange}
        onSubmitEditing={() => void onRedeem()}
        placeholder="اكتب الكود"
        placeholderTextColor={Palette.textFaint}
        returnKeyType="done"
        style={styles.codeInput}
        value={code}
      />
      <Pressable
        accessibilityLabel="تفعيل كود الوصول"
        accessibilityRole="button"
        accessibilityState={{busy, disabled: busy}}
        disabled={busy}
        onPress={() => void onRedeem()}
        style={({pressed}) => [
          styles.codeButton,
          pressed && styles.pressed,
          busy && styles.disabled,
        ]}>
        {busy ? (
          <ActivityIndicator color={Palette.text} size="small" />
        ) : (
          <Text style={styles.codeButtonText}>تفعيل</Text>
        )}
      </Pressable>
    </View>
  </View>
);

export const PlansStep = ({
  accessPlans,
  busy,
  codeBusy,
  courseCode,
  courseCodeEnabled,
  couponBusy,
  hasProjects,
  onCourseCodeChange,
  onRedeemCourseCode,
  onSelectPlan,
  selectedPlan,
}: {
  accessPlans: CourseAccessPlan[];
  busy: boolean;
  codeBusy: boolean;
  courseCode: string;
  courseCodeEnabled: boolean;
  couponBusy: boolean;
  hasProjects: boolean;
  onCourseCodeChange: (value: string) => void;
  onRedeemCourseCode: () => void | Promise<void>;
  onSelectPlan: (plan: CourseAccessPlan) => void;
  selectedPlan?: CourseAccessPlan;
}) => (
  <>
    <Text style={styles.sheetTitle}>اختر الفئة المناسبة لك</Text>
    <Text style={styles.sheetDescription}>
      محتوى الكورس كامل في جميع الفئات
    </Text>
    <View style={styles.planList}>
      {accessPlans.map(plan => (
        <Pressable
          accessibilityRole="button"
          accessibilityState={{
            disabled: codeBusy || couponBusy || busy,
            selected: plan.code === selectedPlan?.code,
          }}
          disabled={codeBusy || couponBusy || busy}
          key={plan.code}
          onPress={() => onSelectPlan(plan)}
          style={({pressed}) => [
            styles.planCard,
            plan.code === selectedPlan?.code && styles.planCardSelected,
            pressed && styles.pressed,
            (codeBusy || couponBusy || busy) && styles.disabled,
          ]}>
          <View style={styles.planHeader}>
            <Text style={styles.planName}>{plan.name}</Text>
            <CoinAmount size={17} value={plan.priceCoins} />
          </View>
          <View style={styles.planBenefits}>
            {planBenefits(plan, hasProjects).map(item => (
              <View key={item} style={styles.planBenefitRow}>
                <View style={styles.planCheck} />
                <Text style={styles.planBenefitText}>{item}</Text>
              </View>
            ))}
          </View>
        </Pressable>
      ))}
    </View>
    {courseCodeEnabled && (
      <CourseCodeEntry
        busy={codeBusy}
        code={courseCode}
        onChange={onCourseCodeChange}
        onRedeem={onRedeemCourseCode}
      />
    )}
  </>
);

export const TopupStep = ({
  balance,
  busy,
  couponApplied,
  onBuyCoins,
  packages,
  purchasePrice,
  rewardContributionLimit,
  rewardContributionPercent,
  selectedPlan,
  shortfall,
  sufficientPackage,
  usableCurrentBalance,
}: {
  balance: number;
  busy: boolean;
  couponApplied: boolean;
  onBuyCoins: (coinPackage: CoinPackage) => void | Promise<void>;
  packages: CoinPackage[];
  purchasePrice: number;
  rewardContributionLimit: number;
  rewardContributionPercent: number;
  selectedPlan?: CourseAccessPlan;
  shortfall: number;
  sufficientPackage?: CoinPackage;
  usableCurrentBalance: number;
}) => (
  <>
    <Text style={styles.sheetEyebrow}>
      {selectedPlan?.name || 'شحن الرصيد'}
    </Text>
    <Text style={styles.sheetTitle}>أكمل رصيدك</Text>
    <Text style={styles.sheetDescription}>
      اختر باقة تغطي الرصيد الناقص
      {'\n'}ثم أكد شراء الكورس
    </Text>
    <View style={styles.topupSummary}>
      <View style={styles.topupMetric}>
        <Text style={styles.summaryLabel}>
          {couponApplied ? 'بعد الخصم' : 'سعر الكورس'}
        </Text>
        <CoinAmount size={18} value={purchasePrice} />
      </View>
      <View style={styles.topupMetric}>
        <Text style={styles.summaryLabel}>رصيدك</Text>
        <CoinAmount size={18} value={balance} />
      </View>
      <View style={styles.topupMetric}>
        <Text style={styles.summaryLabel}>المتاح للشراء</Text>
        <CoinAmount size={18} value={usableCurrentBalance} />
      </View>
    </View>
    {rewardContributionLimit < purchasePrice && (
      <Text style={styles.packageUnavailable}>
        عملات المكافآت تغطي حتى {formatArabicNumber(rewardContributionPercent)}٪
        من هذه الفئة
        {'\n'}ينقصك {formatArabicNumber(shortfall)} عملة ركن
      </Text>
    )}
    <View style={styles.packageList}>
      {packages.length ? (
        packages.map(item => {
          const remainingAfterPurchase = Math.max(
            0,
            balance + item.coins - purchasePrice,
          );
          const isQuickChoice = item.id === sufficientPackage?.id;

          return (
            <Pressable
              accessibilityLabel={`اشحن ${formatArabicNumber(
                item.coins,
              )} عملة ركن مقابل ${
                item.displayPrice || `${formatArabicNumber(item.price)} جنيه`
              }`}
              accessibilityRole="button"
              disabled={busy}
              key={item.id}
              onPress={() => void onBuyCoins(item)}
              style={({pressed}) => [
                styles.packageCard,
                isQuickChoice && styles.packageCardSufficient,
                pressed && styles.pressed,
                busy && styles.disabled,
              ]}>
              <View style={styles.packageCopy}>
                <Text style={styles.packageLabel}>
                  {formatArabicDisplayText(item.label)}
                </Text>
                <View style={styles.planHeader}>
                  <CoinAmount size={18} value={item.coins} />
                  {isQuickChoice && (
                    <Text style={styles.sheetEyebrow}>تغطي المبلغ الناقص</Text>
                  )}
                </View>
                <Text style={styles.packageRemainder}>
                  {item.coins < shortfall
                    ? `تحتاج بعدها ${formatArabicNumber(
                        shortfall - item.coins,
                      )} عملة لإكمال الشراء`
                    : `يتبقى ${formatArabicNumber(
                        remainingAfterPurchase,
                      )} عملة بعد شراء الكورس`}
                </Text>
              </View>
              <Text style={styles.packagePrice}>
                {item.displayPrice || `${formatArabicNumber(item.price)} جنيه`}
              </Text>
            </Pressable>
          );
        })
      ) : (
        <Text style={styles.packageUnavailable}>
          باقات الشحن غير متاحة الآن
        </Text>
      )}
    </View>
  </>
);

export const CouponEntry = ({
  applied,
  busy,
  code,
  disabled,
  discountAmount,
  onApply,
  onChange,
}: {
  applied: boolean;
  busy: boolean;
  code: string;
  disabled: boolean;
  discountAmount: number;
  onApply: () => void | Promise<void>;
  onChange: (value: string) => void;
}) => (
  <View style={styles.codeBox}>
    <Text style={styles.codeTitle}>كود خصم</Text>
    <View style={styles.codeRow}>
      <TextInput
        accessibilityLabel="كود خصم الكورس"
        autoCapitalize="characters"
        autoCorrect={false}
        editable={!disabled && !busy}
        maxLength={50}
        onChangeText={onChange}
        onSubmitEditing={() => void onApply()}
        placeholder="اكتب الكود"
        placeholderTextColor={Palette.textFaint}
        returnKeyType="done"
        style={styles.codeInput}
        value={code}
      />
      <Pressable
        accessibilityRole="button"
        accessibilityState={{
          busy,
          disabled: disabled || busy || !code.trim(),
        }}
        disabled={disabled || busy || !code.trim()}
        onPress={() => void onApply()}
        style={({pressed}) => [
          styles.codeButton,
          pressed && styles.pressed,
          (disabled || busy || !code.trim()) && styles.disabled,
        ]}>
        {busy ? (
          <ActivityIndicator color={Palette.text} size="small" />
        ) : (
          <Text style={styles.codeButtonText}>
            {applied ? 'مطبق' : 'تطبيق'}
          </Text>
        )}
      </Pressable>
    </View>
    {applied && (
      <Text style={styles.reviewCode}>
        خصم {formatArabicNumber(discountAmount)} عملة ركن
      </Text>
    )}
  </View>
);

export const ConfirmStep = ({
  balance,
  couponApplied,
  couponDiscountAmount,
  courseTitle,
  originalPurchasePrice,
  purchasePrice,
  rewardContributionLimit,
  rewardContributionPercent,
  selectedPlan,
}: {
  balance: number;
  couponApplied: boolean;
  couponDiscountAmount: number;
  courseTitle: string;
  originalPurchasePrice: number;
  purchasePrice: number;
  rewardContributionLimit: number;
  rewardContributionPercent: number;
  selectedPlan?: CourseAccessPlan;
}) => (
  <>
    <Text style={styles.sheetEyebrow}>تأكيد الشراء</Text>
    <Text style={styles.sheetTitle}>
      {formatArabicDisplayText(courseTitle)}
    </Text>
    {!!selectedPlan && (
      <View style={styles.selectedPlanSummary}>
        <Text style={styles.selectedPlanName}>{selectedPlan.name}</Text>
        <Text style={styles.selectedPlanDetail}>
          {selectedPlan.chatEnabled
            ? `${formatArabicNumber(
                selectedPlan.chatMessageLimit,
              )} رسالة للاستفسارات`
            : 'محتوى الكورس دون استفسارات'}
        </Text>
      </View>
    )}
    <View style={styles.purchaseSummary}>
      <View>
        <Text style={styles.summaryLabel}>رصيدك</Text>
        <CoinAmount size={18} value={balance} />
      </View>
      <View>
        <Text style={styles.summaryLabel}>سنستخدم</Text>
        <CoinAmount size={18} value={purchasePrice} />
      </View>
      <View>
        <Text style={styles.summaryLabel}>يتبقى</Text>
        <CoinAmount size={18} value={Math.max(0, balance - purchasePrice)} />
      </View>
    </View>
    {couponApplied && (
      <Text style={styles.packageUnavailable}>
        السعر قبل الخصم {formatArabicNumber(originalPurchasePrice)} عملة ركن
        {'\n'}وفرت {formatArabicNumber(couponDiscountAmount)} عملة ركن
      </Text>
    )}
    {rewardContributionLimit < purchasePrice && (
      <Text style={styles.packageUnavailable}>
        يمكنك استخدام المكافآت حتى{' '}
        {formatArabicNumber(rewardContributionPercent)}٪ من السعر
        {'\n'}بحد أقصى {formatArabicNumber(rewardContributionLimit)} عملة
      </Text>
    )}
  </>
);

export const SuccessStep = ({
  grantActivated,
  onStart,
}: {
  grantActivated: boolean;
  onStart: () => void;
}) => (
  <>
    <View style={styles.successMark}>
      <Text style={styles.successMarkText}>✓</Text>
    </View>
    <Text style={[styles.sheetTitle, styles.centerText]}>
      {grantActivated ? 'تم تفعيل المنحة' : 'أصبح الكورس لك'}
    </Text>
    <Text style={[styles.sheetDescription, styles.centerText]}>
      {grantActivated
        ? 'محتوى الكورس متاح لك كاملًا\nيمكنك إضافة الاستفسارات والشهادة لاحقًا'
        : 'يمكنك البدء الآن والعودة إليه في أي وقت'}
    </Text>
    <Pressable
      accessibilityRole="button"
      onPress={onStart}
      style={({pressed}) => [
        styles.sheetPrimary,
        pressed && styles.primaryButtonPressed,
      ]}>
      <Text style={styles.sheetPrimaryText}>
        {grantActivated ? 'ابدأ التعلّم مجانًا' : 'ابدأ أول مقطع'}
      </Text>
    </Pressable>
  </>
);
