import React from 'react';
import {Text, View} from 'react-native';
import RoknCoin from '../../components/ui/RoknCoin';
import {formatArabicNumber} from '../../constants/arabicFormatting';
import {walletStyles as styles} from './walletStyles';

type Props = {
  balance: number | null;
  paidBalance: number;
  rewardBalance: number;
  stacked: boolean;
};

export const WalletBalanceDetails = ({
  balance,
  paidBalance,
  rewardBalance,
  stacked,
}: Props) => (
  <>
    <View style={[styles.breakdownHero, stacked && styles.balanceRowStacked]}>
      <RoknCoin size={28} />
      <View
        style={[
          styles.breakdownHeroCopy,
          stacked && styles.breakdownHeroCopyStacked,
        ]}>
        <Text accessibilityRole="header" style={styles.breakdownCaption}>
          إجمالي الرصيد
        </Text>
        <Text style={styles.breakdownTotal}>
          {balance === null ? '—' : formatArabicNumber(balance)}
        </Text>
      </View>
    </View>

    {[
      {label: 'رصيد مدفوع', value: paidBalance},
      {label: 'مكافآتك', value: rewardBalance},
    ].map(row => (
      <View
        accessible
        accessibilityLabel={`${row.label} ${formatArabicNumber(
          row.value,
        )} من عملات ركن`}
        accessibilityRole="text"
        key={row.label}
        style={[styles.bucketRow, stacked && styles.bucketRowStacked]}>
        <Text style={styles.bucketTitle}>{row.label}</Text>
        <Text style={styles.bucketValue}>{formatArabicNumber(row.value)}</Text>
      </View>
    ))}
    <Text style={styles.bucketPolicy}>
      الخصم والمبلغ المطلوب يظهران عند اختيار الاشتراك
    </Text>
  </>
);
