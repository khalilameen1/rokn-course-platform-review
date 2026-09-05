import React from 'react';
import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import {openGuestLogin} from '../../navigation/journeyNavigation';
import {formatRoknRelativeDate} from '../../utils/dateTime';
import {
  ActivityIndicator,
  Modal,
  Pressable,
  RefreshControl,
  ScrollView,
  Text,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import TabBar from '../../components/TabBar';
import {Container, Content} from '../../components/containers/Containers';
import {
  PremiumCard,
  ResponsiveFrame,
  SectionHeading,
  StatusView,
} from '../../components/ui/PremiumUI';
import HeaderWithBack from '../../components/view/HeaderWithBack';
import {
  Palette,
  Spacing,
  useResponsiveLayout,
} from '../../constants/designSystem';
import RoknCoin, {
  CoinAmount,
  RoknCoinStack,
} from '../../components/ui/RoknCoin';
import {CAN_START_COIN_CHECKOUT} from '../../constants/distribution';
import TaskBrandIcon from '../../components/ui/TaskBrandIcon';
import {
  formatArabicDisplayText,
  formatArabicNumber,
  toArabicDigits,
} from '../../constants/arabicFormatting';
import {useReducedMotion} from '../../hooks/useReducedMotion';
import type {WalletController} from './useWalletController';
import {walletStyles as styles} from './walletStyles';
import {WalletPackageRail} from './WalletPackageRail';

export const WalletView = ({controller}: {controller: WalletController}) => {
  const navigation = useNavigation<RootNavigation>();
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  const {fontScale, gutter, railCardWidth, width} = useResponsiveLayout();
  const packageCardWidth = Math.floor(railCardWidth);
  const stackTaskActions = width < 420 || fontScale > 1.18;
  const balanceArtworkSize =
    fontScale > 1.18 ? 82 : Math.min(104, Math.max(84, width * 0.26));
  const {
    checkoutLoading,
    displayedBalance,
    displayedCoinRules,
    displayedPackages,
    displayedPaidBalance,
    displayedRewardBalance,
    displayedRewardContributionCap,
    displayedSpendableBalance,
    displayedTasks,
    displayedTransactions,
    handleTask,
    manualRefreshing,
    ownerReady,
    packagesStatus,
    refreshWallet,
    refreshWalletManually,
    serverSession,
    setWalletModal,
    startCheckout,
    taskActionLabel,
    taskLoadingIds,
    tasksStatus,
    usingRemoteWallet,
    walletModal,
    walletStatus,
  } = controller;
  if (!ownerReady) {
    return (
      <Container noPadding>
        <Content noPadding>
          <ResponsiveFrame>
            <HeaderWithBack hasArrow={false} title="المحفظة" />
            <PremiumCard style={styles.unavailableCard}>
              <ActivityIndicator color={Palette.primary} />
              <Text style={styles.remoteNote}>جارٍ فتح محفظتك</Text>
            </PremiumCard>
          </ResponsiveFrame>
        </Content>
        <TabBar />
      </Container>
    );
  }

  if (serverSession === false) {
    return (
      <Container noPadding>
        <Content noPadding>
          <ResponsiveFrame>
            <HeaderWithBack hasArrow={false} title="المحفظة" />
            <StatusView
              actionLabel="تسجيل الدخول"
              description="سجّل الدخول لعرض رصيدك ومكافآتك من أي جهاز"
              onAction={() => openGuestLogin(navigation, {name: 'Wallet'})}
              state="empty"
              title="رصيدك مرتبط بحسابك"
            />
          </ResponsiveFrame>
        </Content>
        <TabBar />
      </Container>
    );
  }

  return (
    <Container noPadding>
      <Content
        noPadding
        refreshControl={
          <RefreshControl
            onRefresh={() => void refreshWalletManually()}
            refreshing={manualRefreshing}
            tintColor={Palette.primary}
          />
        }
        paddingBottom={Math.max(Spacing.xl, insets.bottom + Spacing.md)}>
        <ResponsiveFrame>
          <HeaderWithBack hasArrow={false} title="المحفظة" />
          <PremiumCard style={styles.balanceCard}>
            <View style={styles.balanceHeroTop}>
              <View style={styles.balanceHeroCopy}>
                <Text style={styles.balanceCaption}>إجمالي رصيدك</Text>
                <Text style={styles.balanceHeroHint}>
                  استخدمه لفتح الكورسات
                </Text>
              </View>
              <RoknCoinStack
                size={balanceArtworkSize}
                style={styles.coinStack}
              />
            </View>
            <Pressable
              accessibilityHint="يعرض العملات المدفوعة وعملات المكافآت"
              accessibilityLabel="تفاصيل رصيد العملات"
              accessibilityRole="button"
              disabled={displayedBalance === null}
              onPress={() => setWalletModal('breakdown')}
              style={({pressed}) => [
                styles.balanceButton,
                pressed && styles.pressed,
              ]}>
              <View style={styles.balanceRow}>
                <RoknCoin size={34} style={styles.coinSpacing} />
                <Text
                  maxFontSizeMultiplier={2}
                  numberOfLines={1}
                  style={styles.balance}>
                  {displayedBalance === null
                    ? '—'
                    : formatArabicNumber(displayedBalance)}
                </Text>
              </View>
              <Text style={styles.balanceDetails}>عرض التفاصيل</Text>
            </Pressable>
            {displayedBalance === null && walletStatus === 'loading' && (
              <Text style={styles.balanceHint}>جارٍ تحديث الرصيد</Text>
            )}
            {walletStatus === 'error' && (
              <Pressable
                accessibilityRole="button"
                onPress={() => void refreshWallet()}
                style={styles.inlineRetry}>
                <Text style={styles.apiError}>تعذّر تحديث الرصيد</Text>
                <Text style={styles.retryLabel}>إعادة المحاولة</Text>
              </Pressable>
            )}
            <Pressable
              accessibilityRole="button"
              onPress={() => setWalletModal('rules')}
              style={({pressed}) => [
                styles.rulesLink,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.rulesLinkLabel}>كيف يعمل الرصيد؟</Text>
              <Text style={styles.rulesLinkArrow}>‹</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              onPress={() => setWalletModal('transactions')}
              style={({pressed}) => [
                styles.rulesLink,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.rulesLinkLabel}>آخر العمليات</Text>
              <Text style={styles.rulesLinkArrow}>‹</Text>
            </Pressable>
          </PremiumCard>

          {CAN_START_COIN_CHECKOUT && (
            <SectionHeading style={styles.sectionHeading} title="شحن الرصيد" />
          )}
        </ResponsiveFrame>
        {CAN_START_COIN_CHECKOUT && (
          <WalletPackageRail
            cardWidth={packageCardWidth}
            checkoutLoading={checkoutLoading}
            gutter={gutter}
            onCheckout={startCheckout}
            onRetry={() => void refreshWallet()}
            packages={displayedPackages}
            status={packagesStatus}
            usingRemoteWallet={usingRemoteWallet}
          />
        )}

        <ResponsiveFrame>
          {!!displayedPackages.length && packagesStatus === 'error' && (
            <Pressable
              accessibilityRole="button"
              onPress={() => void refreshWallet()}
              style={styles.inlineRetry}>
              <Text style={styles.apiError}>تعذّر تحديث الباقات</Text>
              <Text style={styles.retryLabel}>إعادة المحاولة</Text>
            </Pressable>
          )}
          <SectionHeading
            style={styles.sectionHeading}
            title="اكسب عملات ركن"
            eyebrow="المكافآت المتاحة لك الآن"
          />
          <PremiumCard style={styles.tasksCard}>
            {displayedTasks.length ? (
              <>
                {displayedTasks.map((task, index, allTasks) => {
                  const taskLoading = taskLoadingIds.includes(task.id);
                  const taskActionDisabled =
                    tasksStatus !== 'ready' ||
                    task.status === 'claimed' ||
                    taskLoading;
                  return (
                    <View key={task.id}>
                      <View
                        style={[
                          styles.taskRow,
                          stackTaskActions && styles.taskRowStacked,
                        ]}>
                        <View style={styles.taskMain}>
                          <View style={styles.taskIcon}>
                            <TaskBrandIcon value={task.actionKey} />
                          </View>
                          <View style={styles.taskCopy}>
                            <Text style={styles.taskTitle}>
                              {formatArabicDisplayText(task.title)}
                            </Text>
                            {!!task.description && (
                              <Text style={styles.taskDescription}>
                                {formatArabicDisplayText(task.description)}
                              </Text>
                            )}
                            <View style={styles.taskReward}>
                              <Text style={styles.rewardPlus}>+</Text>
                              <CoinAmount size={15} value={task.reward} />
                            </View>
                          </View>
                        </View>
                        <Pressable
                          accessibilityRole="button"
                          accessibilityState={{
                            busy: taskLoading,
                            disabled: taskActionDisabled,
                          }}
                          disabled={taskActionDisabled}
                          onPress={() => handleTask(task)}
                          style={({pressed}) => [
                            styles.taskAction,
                            stackTaskActions && styles.taskActionStacked,
                            taskActionDisabled && styles.taskActionDone,
                            pressed && styles.pressed,
                          ]}>
                          {taskLoading ? (
                            <ActivityIndicator
                              color={Palette.text}
                              size="small"
                            />
                          ) : (
                            <Text
                              style={[
                                styles.taskActionLabel,
                                task.status === 'claimed' &&
                                  styles.taskActionLabelDone,
                              ]}>
                              {taskActionLabel(task)}
                            </Text>
                          )}
                        </Pressable>
                      </View>
                      {index < allTasks.length - 1 && (
                        <View style={styles.divider} />
                      )}
                    </View>
                  );
                })}
                {tasksStatus === 'error' && (
                  <Pressable
                    accessibilityRole="button"
                    onPress={() => void refreshWallet()}
                    style={styles.inlineRetry}>
                    <Text style={styles.apiError}>تعذّر تحديث المهام</Text>
                    <Text style={styles.retryLabel}>إعادة المحاولة</Text>
                  </Pressable>
                )}
              </>
            ) : (
              <>
                <Text style={styles.remoteNote}>
                  {tasksStatus === 'loading' || tasksStatus === 'idle'
                    ? 'جارٍ تحديث المهام المتاحة'
                    : tasksStatus === 'error'
                    ? 'تعذّر تحميل المهام'
                    : 'أنهيت كل المهام المتاحة حاليًا'}
                </Text>
                {tasksStatus === 'error' && (
                  <Pressable
                    accessibilityRole="button"
                    onPress={() => void refreshWallet()}
                    style={styles.retryButton}>
                    <Text style={styles.retryLabel}>إعادة المحاولة</Text>
                  </Pressable>
                )}
              </>
            )}
          </PremiumCard>
        </ResponsiveFrame>
      </Content>
      <TabBar />
      <Modal
        animationType={reducedMotion ? 'none' : 'fade'}
        onRequestClose={() => setWalletModal(null)}
        statusBarTranslucent
        transparent
        visible={walletModal !== null}>
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
              bounces={false}
              contentContainerStyle={styles.breakdownContent}
              showsVerticalScrollIndicator={false}
              style={styles.breakdownScroll}>
              {walletModal === 'transactions' ? (
                <>
                  <Text style={styles.rulesTitle}>آخر العمليات</Text>
                  {displayedTransactions.length ? (
                    displayedTransactions.map((item, index) => (
                      <View key={item.id}>
                        <View style={styles.transactionRow}>
                          <View style={styles.transactionCopy}>
                            <Text style={styles.transactionTitle}>
                              {formatArabicDisplayText(item.title)}
                            </Text>
                            <Text style={styles.transactionDate}>
                              {toArabicDigits(
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
                        {index < displayedTransactions.length - 1 && (
                          <View style={styles.divider} />
                        )}
                      </View>
                    ))
                  ) : (
                    <Text style={styles.remoteNote}>
                      {walletStatus === 'loading' || walletStatus === 'idle'
                        ? 'جارٍ تحميل العمليات'
                        : walletStatus === 'error'
                        ? 'تعذّر تحميل العمليات'
                        : 'لا توجد عمليات بعد'}
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
                  <Text style={styles.rulesTitle}>كيف يعمل الرصيد؟</Text>
                  <Text style={styles.rulesIntro}>
                    اشحن عملات ركن أو اكسبها من المهام
                    {'\n'}ثم استخدمها لفتح الكورسات
                  </Text>
                  <View style={styles.rulesList}>
                    {displayedCoinRules.length ? (
                      displayedCoinRules.map((rule, index) => (
                        <View key={rule} style={styles.ruleRow}>
                          <View style={styles.ruleNumber}>
                            <Text style={styles.ruleNumberLabel}>
                              {formatArabicNumber(index + 1)}
                            </Text>
                          </View>
                          <Text style={styles.ruleText}>
                            {formatArabicDisplayText(rule)}
                          </Text>
                        </View>
                      ))
                    ) : (
                      <Text style={styles.ruleText}>
                        تعذّر تحميل قواعد الرصيد الآن
                      </Text>
                    )}
                  </View>
                </>
              ) : (
                <>
                  <View style={styles.breakdownHero}>
                    <RoknCoin size={58} style={styles.coinSpacing} />
                    <View style={styles.breakdownHeroCopy}>
                      <Text style={styles.breakdownCaption}>إجمالي الرصيد</Text>
                      <Text
                        maxFontSizeMultiplier={2}
                        numberOfLines={1}
                        style={styles.breakdownTotal}>
                        {formatArabicNumber(displayedBalance ?? 0)}
                      </Text>
                    </View>
                  </View>

                  <View style={styles.bucketRow}>
                    <View style={[styles.bucketDot, styles.paidDot]} />
                    <View style={styles.bucketCopy}>
                      <Text style={styles.bucketTitle}>رصيد مدفوع</Text>
                      <Text style={styles.bucketHint}>من عمليات الشحن</Text>
                    </View>
                    <CoinAmount size={16} value={displayedPaidBalance} />
                  </View>
                  <View style={styles.bucketDivider} />
                  <View style={styles.bucketRow}>
                    <View style={[styles.bucketDot, styles.rewardDot]} />
                    <View style={styles.bucketCopy}>
                      <Text style={styles.bucketTitle}>رصيد مكافآت</Text>
                      <Text style={styles.bucketHint}>ترحيب ومهام</Text>
                    </View>
                    <CoinAmount size={16} value={displayedRewardBalance} />
                  </View>
                  <View style={styles.bucketDivider} />
                  <View style={styles.bucketRow}>
                    <View style={[styles.bucketDot, styles.spendableDot]} />
                    <View style={styles.bucketCopy}>
                      <Text style={styles.bucketTitle}>المتاح لكورس واحد</Text>
                      <Text style={styles.bucketHint}>
                        بعد تطبيق حد المكافآت
                      </Text>
                    </View>
                    <CoinAmount size={16} value={displayedSpendableBalance} />
                  </View>
                  <Text style={styles.bucketPolicy}>
                    عند فتح كورس نستخدم المكافآت أولًا بحد أقصى{' '}
                    {formatArabicNumber(displayedRewardContributionCap)} ثم
                    الرصيد المدفوع
                  </Text>
                </>
              )}
              <Pressable
                accessibilityRole="button"
                onPress={() => setWalletModal(null)}
                style={({pressed}) => [
                  styles.breakdownClose,
                  pressed && styles.pressed,
                ]}>
                <Text style={styles.breakdownCloseLabel}>تم</Text>
              </Pressable>
            </ScrollView>
          </Pressable>
        </Pressable>
      </Modal>
    </Container>
  );
};

export default WalletView;
