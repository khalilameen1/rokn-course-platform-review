import React, {useEffect, useState} from 'react';
import {ActivityIndicator, Pressable, Text, View} from 'react-native';
import {AccordionArrowDown, AccordionArrowUp} from '../../assets/SVG';
import {
  formatArabicDisplayText,
  formatArabicNumber,
} from '../../constants/arabicFormatting';
import {Palette} from '../../constants/designSystem';
import TaskBrandIcon from '../../components/ui/TaskBrandIcon';
import {CoinAmount} from '../../components/ui/RoknCoin';
import type {WalletController} from './useWalletController';
import {rewardTaskTitle} from './rewardsPresentation';
import {walletStyles as styles} from './walletStyles';

export const RewardsTaskList = ({
  controller,
  stacked,
}: {
  controller: WalletController;
  stacked: boolean;
}) => {
  const {
    displayedTasks,
    tasksStatus,
    taskLoadingIds,
    handleTask,
    taskActionLabel,
    refreshWallet,
  } = controller;
  const [expanded, setExpanded] = useState(false);
  const active = displayedTasks.filter(task => task.status !== 'claimed');
  const completed = displayedTasks.filter(task => task.status === 'claimed');
  useEffect(() => {
    if (!completed.length) setExpanded(false);
  }, [completed.length]);
  return (
    <View style={styles.tasksCard}>
      <Text accessibilityRole="header" style={styles.rewardsSectionTitle}>
        اكسب عملات
      </Text>
      {active.map(task => {
        const busy = taskLoadingIds.includes(task.id);
        const disabled = tasksStatus !== 'ready' || busy;
        return (
          <View key={task.id} style={styles.rewardTaskDivider}>
            <View style={[styles.taskRow, stacked && styles.taskRowStacked]}>
              <View style={styles.taskMain}>
                <View style={styles.taskIcon}>
                  <TaskBrandIcon value={task.actionKey} plain />
                </View>
                <View style={styles.taskCopy}>
                  <Text style={styles.taskTitle}>
                    {formatArabicDisplayText(rewardTaskTitle(task))}
                  </Text>
                  <View style={styles.taskReward}>
                    <Text style={styles.rewardPlus}>+</Text>
                    <CoinAmount size={18} value={task.reward} />
                  </View>
                </View>
              </View>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={`${taskActionLabel(task)} ${rewardTaskTitle(
                  task,
                )}`}
                accessibilityState={{busy, disabled}}
                disabled={disabled}
                onPress={() => void handleTask(task)}
                style={({pressed}) => [
                  styles.taskAction,
                  stacked && styles.taskActionStacked,
                  disabled && styles.taskActionDone,
                  pressed && styles.pressed,
                ]}>
                {busy ? (
                  <ActivityIndicator color={Palette.text} size="small" />
                ) : (
                  <Text style={styles.taskActionLabel}>
                    {taskActionLabel(task)}
                  </Text>
                )}
              </Pressable>
            </View>
          </View>
        );
      })}
      {!active.length && (
        <Text style={styles.remoteNote}>
          {tasksStatus === 'loading' || tasksStatus === 'idle'
            ? 'جارٍ تحميل المهام'
            : tasksStatus === 'error'
            ? 'تعذّر تحميل المهام'
            : 'لا توجد مهام جديدة حاليًا'}
        </Text>
      )}
      {tasksStatus === 'error' && (
        <Pressable
          accessibilityRole="button"
          onPress={() => void refreshWallet()}
          style={styles.inlineRetry}>
          {!!active.length && (
            <Text style={styles.apiError}>تعذّر تحديث المهام</Text>
          )}
          <Text style={styles.retryLabel}>إعادة المحاولة</Text>
        </Pressable>
      )}
      {!!completed.length && (
        <>
          <Pressable
            accessibilityRole="button"
            accessibilityState={{expanded}}
            onPress={() => setExpanded(value => !value)}
            style={styles.disclosure}>
            <Text style={styles.rulesLinkLabel}>المهام المكتملة</Text>
            <Text style={styles.rulesLinkLabel}>
              {formatArabicNumber(completed.length)}
            </Text>
            {expanded ? (
              <AccordionArrowUp width={14} height={14} />
            ) : (
              <AccordionArrowDown width={14} height={14} />
            )}
          </Pressable>
          {expanded &&
            completed.map(task => (
              <View key={task.id} style={styles.completedTask}>
                <Text style={styles.completedTaskTitle}>
                  {formatArabicDisplayText(rewardTaskTitle(task))}
                </Text>
                <Text style={styles.rulesLinkLabel}>تم الاستلام</Text>
              </View>
            ))}
        </>
      )}
    </View>
  );
};
