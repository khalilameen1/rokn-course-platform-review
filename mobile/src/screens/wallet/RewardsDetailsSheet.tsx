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
import {CloseChat} from '../../assets/SVG';
import RoknCoin from '../../components/ui/RoknCoin';
import type {WalletController} from './useWalletController';
import {walletStyles as styles} from './walletStyles';

export const RewardsDetailsSheet = ({
  controller,
}: {
  controller: WalletController;
}) => {
  const {
    walletModal,
    setWalletModal,
    displayedTransactions,
    displayedCoinRules,
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
          <View style={styles.sheetHeader}>
            <Text accessibilityRole="header" style={styles.rulesTitle}>
              {walletModal === 'transactions'
                ? 'آخر حركات الرصيد'
                : 'كيف يعمل الرصيد'}
            </Text>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="إغلاق التفاصيل"
              onPress={() => setWalletModal(null)}
              style={styles.sheetClose}>
              <CloseChat width={18} height={18} />
            </Pressable>
          </View>
          <ScrollView
            style={styles.breakdownScroll}
            contentContainerStyle={styles.breakdownContent}>
            {walletModal === 'transactions' ? (
              <>
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
                    <View style={styles.transactionAmount}>
                      <Text
                        style={[
                          styles.transactionValue,
                          item.amount > 0 && styles.positive,
                        ]}>
                        {`\u2066${
                          item.amount > 0 ? '+' : '−'
                        } ${formatArabicNumber(Math.abs(item.amount))}\u2069`}
                      </Text>
                      <RoknCoin size={18} />
                    </View>
                  </View>
                ))}
                {!displayedTransactions.length && (
                  <Text style={styles.remoteNote}>
                    {walletStatus === 'loading' || walletStatus === 'idle'
                      ? 'جارٍ تحميل السجل'
                      : walletStatus === 'error'
                      ? 'تعذّر تحميل السجل'
                      : 'لا توجد حركات رصيد بعد'}
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
            ) : (
              <>
                {displayedCoinRules.map((rule, index) => (
                  <Text key={`${index}-${rule}`} style={styles.rulesIntro}>
                    {formatArabicDisplayText(rule)}
                  </Text>
                ))}
              </>
            )}
          </ScrollView>
        </Pressable>
      </Pressable>
    </Modal>
  );
};
