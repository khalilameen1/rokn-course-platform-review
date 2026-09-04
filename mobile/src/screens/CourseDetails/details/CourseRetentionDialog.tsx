import React from 'react';
import {Modal, Pressable, ScrollView, Text, View} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {useReducedMotion} from '../../../hooks/useReducedMotion';
import styles from './styles';

type CourseRetentionDialogProps = {
  bottomInset: number;
  isTablet: boolean;
  onClose: () => void;
  onOpenWallet: () => void;
  owned: boolean;
  retentionVisible: boolean;
};

export const CourseRetentionDialog = ({
  bottomInset,
  isTablet,
  onClose,
  onOpenWallet,
  owned,
  retentionVisible,
}: CourseRetentionDialogProps) => {
  const reducedMotion = useReducedMotion();
  const insets = useSafeAreaInsets();
  const horizontalPadding = isTablet ? 28 : 18;
  return (
    <Modal
      animationType={reducedMotion ? 'none' : 'fade'}
      onRequestClose={onClose}
      statusBarTranslucent
      transparent
      visible={retentionVisible && !owned}>
      <View style={styles.modalRoot}>
        <Pressable
          accessibilityLabel="إغلاق اقتراح مهام العملات"
          accessibilityRole="button"
          onPress={onClose}
          style={styles.modalBackdrop}
        />
        <View
          accessibilityViewIsModal
          style={[
            styles.sheet,
            styles.retentionSheet,
            {
              paddingBottom: Math.max(bottomInset, 16) + 10,
              paddingLeft: Math.max(horizontalPadding, insets.left + 12),
              paddingRight: Math.max(horizontalPadding, insets.right + 12),
            },
          ]}>
          <View style={styles.sheetHandle} />
          <ScrollView
            bounces={false}
            contentContainerStyle={styles.retentionContent}
            showsVerticalScrollIndicator={false}>
            <View style={styles.retentionMark}>
              <Text style={styles.retentionMarkText}>＋</Text>
            </View>
            <Text style={[styles.sheetTitle, styles.centerText]}>
              يمكنك المتابعة دون شحن الآن
            </Text>
            <Text style={[styles.sheetDescription, styles.centerText]}>
              أنجز مهمة مرة واحدة واحصل على عملات ركن
              {'\n'}ثم ارجع للكورس من مكانك
            </Text>
            <Pressable
              accessibilityRole="button"
              onPress={() => {
                onClose();
                onOpenWallet();
              }}
              style={({pressed}) => [
                styles.sheetPrimary,
                pressed && styles.primaryButtonPressed,
              ]}>
              <Text style={styles.sheetPrimaryText}>عرض مهام العملات</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              onPress={onClose}
              style={({pressed}) => [
                styles.retentionSecondary,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.retentionSecondaryText}>ليس الآن</Text>
            </Pressable>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
};
