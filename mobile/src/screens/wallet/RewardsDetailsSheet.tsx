import React from 'react';
import {Modal, Pressable, ScrollView, Text, View} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {useReducedMotion} from '../../hooks/useReducedMotion';
import {Spacing} from '../../constants/designSystem';
import {
  formatArabicDisplayText,
  formatArabicNumber,
  toArabicDigits,
} from '../../constants/arabicFormatting';
import {formatRoknRelativeDate} from '../../utils/dateTime';
import {WalletBalanceDetails} from './WalletBalanceDetails';
import type {WalletController} from './useWalletController';
import {walletStyles as styles} from './walletStyles';

export const RewardsDetailsSheet = ({
  controller,
  stacked,
}: {
  controller: WalletController;
  stacked: boolean;
}) => {
  const {
    walletModal,
    setWalletModal,
    displayedTransactions,
    displayedCoinRules,
    displayedBalance,
    displayedPaidBalance,
    displayedRewardBalance,
    walletStatus,
    refreshWallet,
  } = controller;
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  if (walletModal === null) return null;
  return (
    <Modal
      animationType={reducedMotion ? 'none' : 'slide'}
      transparent
      statusBarTranslucent
      visible
      onRequestClose={() => setWalletModal(null)}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="إغلاق"
        onPress={() => setWalletModal(null)}
        style={styles.breakdownOverlay}>
        <Pressable
          accessible={false}
          accessibilityViewIsModal
          onPress={event => event.stopPropagation()}
          style={[
            styles.breakdownSheet,
            {
              paddingBottom: Math.max(Spacing.xl, insets.bottom + Spacing.md),
              paddingLeft: Math.max(Spacing.xl, insets.left + Spacing.md),
              paddingRight: Math.max(Spacing.xl, insets.right + Spacing.md),
            },
          ]}>
          <View style={styles.breakdownHandle} />
          <ScrollView
            style={styles.breakdownScroll}
            contentContainerStyle={styles.breakdownContent}>
            {walletModal === 'transactions' ? (
              <>
                <Text accessibilityRole="header" style={styles.rulesTitle}>
                  سجل المكافآت
                </Text>
                {displayedTransactions.map(item => (
                  <View key={item.id} style={styles.transactionRow}>
                    <View style={styles.transactionCopy}>
                      <Text style={styles.transactionTitle}>
                        {formatArabicDisplayText(item.title)}
                      </Text>
                      <Text style={styles.transactionDate}>
                        {item.automatic
                          ? 'أضيفت تلقائيًا'
                          : toArabicDigits(
                              formatRoknRelativeDate(item.createdAt),
                            )}
                      </Text>
                    </View>
                    <Text
                      style={[
                        styles.transactionValue,
                        item.amount > 0 && styles.positive,
                      ]}>
                      {item.amount > 0 ? '+' : '−'}
                      {formatArabicNumber(Math.abs(item.amount))}
                    </Text>
                  </View>
                ))}
                {!displayedTransactions.length && (
                  <Text style={styles.remoteNote}>
                    {walletStatus === 'loading' || walletStatus === 'idle'
                      ? 'جارٍ تحميل السجل'
                      : walletStatus === 'error'
                      ? 'تعذّر تحميل السجل'
                      : 'لا توجد مكافآت بعد'}
                  </Text>
                )}
                {walletStatus === 'error' && (
                  <Pressable
                    accessibilityRole="button"
                    onPress={() => void refreshWallet()}
                    style={styles.retryButton}>
                    <Text style={styles.retryLabel}>إعادة المحاولة</Text>
                  </Pressable>
                )}
              </>
            ) : walletModal === 'rules' ? (
              <>
                <Text accessibilityRole="header" style={styles.rulesTitle}>
                  كيف يعمل الرصيد
                </Text>
                {displayedCoinRules.map((rule, index) => (
                  <Text key={`${index}-${rule}`} style={styles.rulesIntro}>
                    {formatArabicDisplayText(rule)}
                  </Text>
                ))}
                {displayedPaidBalance > 0 && (
                  <Pressable
                    accessibilityRole="button"
                    onPress={() => setWalletModal('breakdown')}
                    style={styles.disclosure}>
                    <Text style={styles.rulesLinkLabel}>
                      رصيد مدفوع متاح عند الشراء
                    </Text>
                  </Pressable>
                )}
              </>
            ) : (
              <WalletBalanceDetails
                balance={displayedBalance}
                paidBalance={displayedPaidBalance}
                rewardBalance={displayedRewardBalance}
                stacked={stacked}
              />
            )}
            <Pressable
              accessibilityRole="button"
              onPress={() => setWalletModal(null)}
              style={styles.breakdownClose}>
              <Text style={styles.breakdownCloseLabel}>تم</Text>
            </Pressable>
          </ScrollView>
        </Pressable>
      </Pressable>
    </Modal>
  );
};
