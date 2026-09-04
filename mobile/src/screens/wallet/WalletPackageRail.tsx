import React from 'react';
import {Pressable, ScrollView, Text} from 'react-native';

import {PremiumCard, ResponsiveFrame} from '../../components/ui/PremiumUI';
import Package from '../../components/view/Package';
import {Spacing} from '../../constants/designSystem';
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
        showsHorizontalScrollIndicator={false}>
        {packages.map(item => (
          <Package
            buttonTitle={
              checkoutLoading === item.id
                ? 'جارٍ فتح الدفع'
                : checkoutLoading
                ? 'جارٍ فتح باقة أخرى'
                : !catalogueReady
                ? 'حدّث الباقات أولًا'
                : 'اختيار الباقة'
            }
            disabled={Boolean(checkoutLoading) || !catalogueReady}
            key={item.id}
            onPress={() => onCheckout(item)}
            price={String(item.price)}
            displayPrice={item.displayPrice}
            rPrice={String(item.coins)}
            title={item.label}
            width={cardWidth}
          />
        ))}
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
