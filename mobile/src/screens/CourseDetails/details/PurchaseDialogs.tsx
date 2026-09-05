import React from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  Text,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {Palette} from '../../../constants/designSystem';
import {useReducedMotion} from '../../../hooks/useReducedMotion';
import type {CoinPackage} from '../../../services/api/coinPackageMapper';
import type {CourseAccessPlan} from '../../../services/roknApi';
import {
  ConfirmStep,
  CouponEntry,
  PlansStep,
  SuccessStep,
  TopupStep,
} from './PurchaseDialogSteps';
import styles from './styles';
import type {DialogStep} from './useCoursePurchaseFlow';

export type {DialogStep} from './useCoursePurchaseFlow';
export {CourseRetentionDialog} from './CourseRetentionDialog';

type CoursePurchaseDialogProps = {
  accessPlans: CourseAccessPlan[];
  balance: number;
  bottomInset: number;
  busy: boolean;
  codeBusy?: boolean;
  courseTitle: string;
  projectCount?: number;
  courseCode?: string;
  courseCodeEnabled?: boolean;
  couponApplied?: boolean;
  couponBusy?: boolean;
  couponCode?: string;
  couponDiscountAmount?: number;
  dialogStep: DialogStep;
  grantActivated: boolean;
  isTablet: boolean;
  notice: string;
  onBuyCoins: (coinPackage: CoinPackage) => void | Promise<void>;
  onApplyCoupon?: () => void | Promise<void>;
  onCouponCodeChange?: (value: string) => void;
  onChangePlan?: () => void;
  onClose: () => void;
  onConfirmPurchase: () => void | Promise<void>;
  onCourseCodeChange?: (value: string) => void;
  onRedeemCourseCode?: () => void | Promise<void>;
  onSelectPlan: (plan: CourseAccessPlan) => void;
  onSuccessStart: () => void;
  packages: CoinPackage[];
  originalPurchasePrice?: number;
  purchasePrice: number;
  rewardContributionLimit: number;
  rewardContributionPercent: number;
  selectedPlan?: CourseAccessPlan;
  shortfall: number;
  sufficientPackage?: CoinPackage;
  usableCurrentBalance: number;
};

export const CoursePurchaseDialog = ({
  accessPlans,
  balance,
  bottomInset,
  busy,
  codeBusy = false,
  courseTitle,
  projectCount = 0,
  courseCode = '',
  courseCodeEnabled = false,
  couponApplied = false,
  couponBusy = false,
  couponCode = '',
  couponDiscountAmount = 0,
  dialogStep,
  grantActivated,
  isTablet,
  notice,
  onBuyCoins,
  onApplyCoupon = () => undefined,
  onCouponCodeChange = () => undefined,
  onChangePlan = () => undefined,
  onClose,
  onConfirmPurchase,
  onCourseCodeChange = () => undefined,
  onRedeemCourseCode = () => undefined,
  onSelectPlan,
  onSuccessStart,
  packages,
  purchasePrice,
  originalPurchasePrice = purchasePrice,
  rewardContributionLimit,
  rewardContributionPercent,
  selectedPlan,
  shortfall,
  sufficientPackage,
  usableCurrentBalance,
}: CoursePurchaseDialogProps) => {
  const reducedMotion = useReducedMotion();
  const insets = useSafeAreaInsets();
  const horizontalPadding = isTablet ? 28 : 18;
  const interactionBusy = busy || couponBusy || codeBusy;

  return (
    <Modal
      animationType={reducedMotion ? 'none' : 'slide'}
      onRequestClose={() => {
        if (!interactionBusy) onClose();
      }}
      statusBarTranslucent
      transparent
      visible={dialogStep !== null}>
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        style={styles.modalRoot}>
        <Pressable
          accessibilityLabel="إغلاق"
          accessibilityRole="button"
          accessibilityState={{disabled: interactionBusy}}
          disabled={interactionBusy}
          onPress={onClose}
          style={styles.modalBackdrop}
        />
        <View
          accessibilityLabel="خيارات شراء الكورس"
          accessibilityViewIsModal
          style={[
            styles.sheet,
            {
              paddingBottom: Math.max(bottomInset, 16) + 10,
              paddingLeft: Math.max(horizontalPadding, insets.left + 12),
              paddingRight: Math.max(horizontalPadding, insets.right + 12),
            },
          ]}>
          <View style={styles.sheetHandle} />
          <ScrollView
            automaticallyAdjustKeyboardInsets
            bounces={false}
            contentContainerStyle={styles.sheetContent}
            keyboardShouldPersistTaps="handled"
            showsVerticalScrollIndicator={false}
            style={styles.sheetScroll}>
            {dialogStep === 'plans' && (
              <PlansStep
                accessPlans={accessPlans}
                busy={busy}
                codeBusy={codeBusy}
                courseCode={courseCode}
                courseCodeEnabled={courseCodeEnabled}
                couponBusy={couponBusy}
                hasProjects={projectCount > 0}
                onCourseCodeChange={onCourseCodeChange}
                onRedeemCourseCode={onRedeemCourseCode}
                onSelectPlan={onSelectPlan}
                selectedPlan={selectedPlan}
              />
            )}
            {dialogStep === 'topup' && (
              <TopupStep
                balance={balance}
                busy={interactionBusy}
                couponApplied={couponApplied}
                onBuyCoins={onBuyCoins}
                packages={packages}
                purchasePrice={purchasePrice}
                rewardContributionLimit={rewardContributionLimit}
                rewardContributionPercent={rewardContributionPercent}
                selectedPlan={selectedPlan}
                shortfall={shortfall}
                sufficientPackage={sufficientPackage}
                usableCurrentBalance={usableCurrentBalance}
              />
            )}
            {dialogStep === 'confirm' && (
              <ConfirmStep
                balance={balance}
                couponApplied={couponApplied}
                couponDiscountAmount={couponDiscountAmount}
                courseTitle={courseTitle}
                originalPurchasePrice={originalPurchasePrice}
                purchasePrice={purchasePrice}
                rewardContributionLimit={rewardContributionLimit}
                rewardContributionPercent={rewardContributionPercent}
                selectedPlan={selectedPlan}
              />
            )}
            {(dialogStep === 'confirm' || dialogStep === 'topup') && (
              <CouponEntry
                applied={couponApplied}
                busy={couponBusy}
                code={couponCode}
                disabled={busy}
                discountAmount={couponDiscountAmount}
                onApply={onApplyCoupon}
                onChange={onCouponCodeChange}
              />
            )}
            {dialogStep === 'confirm' && (
              <Pressable
                accessibilityRole="button"
                accessibilityState={{busy, disabled: interactionBusy}}
                disabled={interactionBusy}
                onPress={onConfirmPurchase}
                style={({pressed}) => [
                  styles.sheetPrimary,
                  pressed && styles.primaryButtonPressed,
                  interactionBusy && styles.disabled,
                ]}>
                {busy ? (
                  <ActivityIndicator color={Palette.text} />
                ) : (
                  <Text style={styles.sheetPrimaryText}>تأكيد الشراء</Text>
                )}
              </Pressable>
            )}
            {(dialogStep === 'confirm' || dialogStep === 'topup') &&
              accessPlans.length > 1 && (
                <Pressable
                  accessibilityLabel="تغيير فئة الكورس"
                  accessibilityRole="button"
                  accessibilityState={{disabled: interactionBusy}}
                  disabled={interactionBusy}
                  onPress={onChangePlan}
                  style={({pressed}) => [
                    styles.retentionSecondary,
                    pressed && styles.pressed,
                    interactionBusy && styles.disabled,
                  ]}>
                  <Text style={styles.retentionSecondaryText}>تغيير الفئة</Text>
                </Pressable>
              )}
            {dialogStep === 'success' && (
              <SuccessStep
                grantActivated={grantActivated}
                onStart={onSuccessStart}
              />
            )}
            {!!notice && (
              <Text accessibilityLiveRegion="polite" style={styles.notice}>
                {notice}
              </Text>
            )}
            {busy && dialogStep === 'topup' && (
              <View style={styles.busyRow}>
                <ActivityIndicator color={Palette.primary} size="small" />
                <Text style={styles.busyText}>جارٍ فتح الدفع</Text>
              </View>
            )}
          </ScrollView>
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
};
