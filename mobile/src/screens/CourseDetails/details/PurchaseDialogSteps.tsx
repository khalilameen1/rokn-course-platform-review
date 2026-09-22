import React from 'react';
import {Pressable, Text, View} from 'react-native';
import {CoinAmount} from '../../../components/ui/RoknCoin';
import {
  formatArabicDisplayText,
  formatArabicNumber,
  formatAuthoredDisplayText,
} from '../../../constants/arabicFormatting';
import type {CoinPackage} from '../../../services/api/coinPackageMapper';
import type {CourseAccessPlan} from '../../../services/roknApi';
import styles from './styles';

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
      {selectedPlan
        ? `الفئة: ${formatAuthoredDisplayText(selectedPlan.name)}`
        : 'شحن الرصيد'}
    </Text>
    <Text style={styles.sheetTitle}>أكمل رصيدك</Text>
    <Text style={styles.sheetDescription}>
      اشحن الرصيد الناقص، ثم أكد شراء الكورس.
    </Text>
    <View style={styles.topupSummary}>
      <View style={styles.topupMetric}>
        <Text style={styles.topupMetricLabel}>
          {couponApplied ? 'إجمالي السعر بعد الخصم' : 'إجمالي سعر الفئة'}
        </Text>
        <CoinAmount
          size={18}
          style={styles.summaryCoins}
          textStyle={styles.topupMetricValue}
          value={purchasePrice}
        />
      </View>
      <View style={styles.topupMetric}>
        <Text style={styles.topupMetricLabel}>المتاح لهذا الكورس</Text>
        <CoinAmount
          size={18}
          style={styles.summaryCoins}
          textStyle={styles.topupMetricValue}
          value={usableCurrentBalance}
        />
      </View>
      <View style={styles.shortfallRow}>
        <Text style={styles.shortfallLabel}>تحتاج إلى شحن</Text>
        <CoinAmount
          size={20}
          style={styles.summaryCoins}
          textStyle={styles.shortfallValue}
          value={shortfall}
        />
      </View>
    </View>
    {balance > usableCurrentBalance && (
      <Text style={styles.topupBalanceNote}>
        إجمالي رصيد المحفظة {formatArabicNumber(balance)} عملة؛ ليس كله متاحًا
        لهذا الكورس.
      </Text>
    )}
    {rewardContributionLimit < purchasePrice && (
      <Text style={styles.topupBalanceNote}>
        المكافآت تغطي حتى {formatArabicNumber(rewardContributionLimit)} عملة
        {' (نحو '}
        {formatArabicNumber(rewardContributionPercent)}٪) من سعر الفئة.
      </Text>
    )}
    <View style={styles.packageList}>
      {packages.length ? (
        packages.map(item => {
          const remainingAfterPurchase = Math.max(
            0,
            balance + item.coins - purchasePrice,
          );
          const canCompletePurchase = item.coins >= shortfall;
          const isQuickChoice =
            canCompletePurchase && item.id === sufficientPackage?.id;
          const priceLabel =
            item.displayPrice || `${formatArabicNumber(item.price)} جنيه`;
          const remainderLabel = canCompletePurchase
            ? `يتبقى ${formatArabicNumber(
                remainingAfterPurchase,
              )} عملة إجمالًا في المحفظة بعد شراء الكورس`
            : `تحتاج بعدها ${formatArabicNumber(
                shortfall - item.coins,
              )} عملة لإكمال الشراء`;

          return (
            <Pressable
              accessibilityLabel={[
                formatArabicDisplayText(item.label),
                `اشحن ${formatArabicNumber(
                  item.coins,
                )} عملة ركن مقابل ${priceLabel}`,
                isQuickChoice ? 'تغطي المبلغ الناقص' : '',
                remainderLabel,
              ]
                .filter(Boolean)
                .join(' — ')}
              accessibilityHint={
                canCompletePurchase
                  ? 'يشحن المحفظة أولًا، ثم يمكنك تأكيد شراء الكورس'
                  : 'هذه الباقة لا تغطي الرصيد الناقص لهذا الكورس'
              }
              accessibilityRole="button"
              accessibilityState={{
                busy,
                disabled: busy || !canCompletePurchase,
              }}
              disabled={busy || !canCompletePurchase}
              key={item.id}
              onPress={() => void onBuyCoins(item)}
              style={({pressed}) => [
                styles.packageCard,
                isQuickChoice && styles.packageCardSufficient,
                pressed && styles.pressed,
                (busy || !canCompletePurchase) && styles.disabled,
              ]}>
              <View style={styles.packageHeading}>
                <Text style={styles.packageLabel}>
                  {formatArabicDisplayText(item.label)}
                </Text>
                {isQuickChoice && (
                  <Text style={styles.packageBadge}>تغطي المبلغ الناقص</Text>
                )}
              </View>
              <View style={styles.packageAmountRow}>
                <CoinAmount
                  size={24}
                  style={styles.packageCoins}
                  textStyle={styles.packageCoinsText}
                  value={item.coins}
                />
                <Text style={styles.packagePrice}>
                  {canCompletePurchase ? `اشحن بـ ${priceLabel}` : priceLabel}
                </Text>
              </View>
              <Text style={styles.packageRemainder}>{remainderLabel}</Text>
            </Pressable>
          );
        })
      ) : (
        <Text style={styles.packageUnavailable}>
          لا توجد باقة مناسبة لإكمال الشراء الآن
        </Text>
      )}
    </View>
  </>
);
