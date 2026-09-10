import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  Text,
  View,
} from 'react-native';

import {PremiumCard, ResponsiveFrame} from '../../components/ui/PremiumUI';
import {CoinAmount} from '../../components/ui/RoknCoin';
import {
  formatArabicDisplayText,
  formatArabicNumber,
} from '../../constants/arabicFormatting';
import {Palette, Spacing} from '../../constants/designSystem';
import type {CoinPackage} from '../../services/api/coinPackageMapper';
import type {WalletAreaStatus} from './useWalletData';
import {walletStyles as styles} from './walletStyles';

type Props = {
  cardWidth: number;
  checkoutLoading: string | null;
  gutter: number;
  onCheckout: (item: CoinPackage) => void;
  onRetry: () => void;
  packages: CoinPackage[];
  status: WalletAreaStatus;
  usingRemoteWallet: boolean;
};

export const WalletPackageRail = ({
  cardWidth,
  checkoutLoading,
  gutter,
  onCheckout,
  onRetry,
  packages,
  status,
  usingRemoteWallet,
}: Props) => {
  if (packages.length) {
    const catalogueReady = status === 'ready';
    return (
      <ScrollView
        accessibilityLabel="باقات شحن الرصيد"
        contentContainerStyle={[
          styles.packages,
          {gap: Spacing.sm, paddingHorizontal: gutter},
        ]}
        decelerationRate="fast"
        horizontal
        nestedScrollEnabled
        snapToInterval={cardWidth + Spacing.sm}
        snapToAlignment="start"
        disableIntervalMomentum
        showsHorizontalScrollIndicator={false}>
        {packages.map(item => {
          const busy = checkoutLoading === item.id;
          const disabled = Boolean(checkoutLoading) || !catalogueReady;
          const price =
            item.displayPrice ||
            `${formatArabicNumber(item.price, {
              maximumFractionDigits: 2,
            })} جنيه`;
          const actionLabel = busy
            ? 'جارٍ فتح الدفع'
            : checkoutLoading
            ? 'جارٍ فتح باقة أخرى'
            : !catalogueReady
            ? 'حدّث الباقات أولًا'
            : 'اختيار الباقة';
          return (
            <Pressable
              accessibilityLabel={`${
                item.label ? `${formatArabicDisplayText(item.label)}، ` : ''
              }${formatArabicNumber(item.coins)} من رصيد ركن مقابل ${price}`}
              accessibilityRole="button"
              accessibilityState={{busy, disabled}}
              disabled={disabled}
              key={item.id}
              onPress={() => onCheckout(item)}
              style={({pressed}) => [
                styles.packageCard,
                {width: cardWidth},
                disabled && styles.packageDisabled,
                pressed && styles.pressed,
              ]}>
              {!!item.label && (
                <Text style={styles.packageLabel}>
                  {formatArabicDisplayText(item.label)}
                </Text>
              )}
              <CoinAmount
                size={20}
                style={styles.packageAmount}
                textStyle={styles.packageCoins}
                value={item.coins}
              />
              <Text style={styles.packagePrice}>{price}</Text>
              <View style={styles.packageAction}>
                {busy && (
                  <ActivityIndicator color={Palette.text} size="small" />
                )}
                <Text style={styles.packageActionLabel}>{actionLabel}</Text>
              </View>
            </Pressable>
          );
        })}
      </ScrollView>
    );
  }

  if (!usingRemoteWallet) return null;
  return (
    <ResponsiveFrame>
      <PremiumCard style={styles.unavailableCard}>
        <Text style={styles.remoteNote}>
          {status === 'loading' || status === 'idle'
            ? 'جارٍ تحديث باقات الرصيد'
            : status === 'ready'
            ? 'لا توجد باقات متاحة الآن'
            : 'تعذّر تحميل الباقات الآن'}
        </Text>
        {status === 'error' && (
          <Pressable
            accessibilityRole="button"
            onPress={onRetry}
            style={styles.retryButton}>
            <Text style={styles.retryLabel}>إعادة المحاولة</Text>
          </Pressable>
        )}
      </PremiumCard>
    </ResponsiveFrame>
  );
};
