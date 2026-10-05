import React from 'react';
import {
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {AppArtwork} from '../../../components/ui/AppArtwork';
import {
  Accessibility,
  Palette,
  textDirection,
} from '../../../constants/designSystem';
import {Fonts} from '../../../constants/styleConstants';
import {useReducedMotion} from '../../../hooks/useReducedMotion';

type CourseRetentionDialogProps = {
  bottomInset: number;
  isTablet: boolean;
  onClose: () => void;
  onOpenWallet: () => void;
  owned: boolean;
  retentionVisible: boolean;
};

/** Approved gift-family composition; eligibility has one owner in purchase entry. */
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
  const gutter = isTablet ? 28 : 20;
  return (
    <Modal
      animationType={reducedMotion ? 'none' : 'fade'}
      onRequestClose={onClose}
      statusBarTranslucent
      transparent
      visible={retentionVisible && !owned}>
      <View style={styles.overlay}>
        <ScrollView
          bounces={false}
          showsVerticalScrollIndicator={false}
          contentContainerStyle={[
            styles.content,
            {
              paddingTop: Math.max(insets.top + 16, 24),
              paddingBottom: Math.max(insets.bottom, bottomInset, 8) + 16,
              paddingLeft: Math.max(gutter, insets.left + 16),
              paddingRight: Math.max(gutter, insets.right + 16),
            },
          ]}>
          <View
            accessibilityViewIsModal
            testID="purchase-exit-card"
            style={styles.card}>
            <Pressable
              accessibilityLabel="إغلاق"
              accessibilityRole="button"
              onPress={onClose}
              style={styles.close}>
              <Text style={styles.closeText}>×</Text>
            </Pressable>
            <View style={styles.visual}>
              <AppArtwork
                asset="coin_stack"
                resizeMode="contain"
                accessibilityElementsHidden
                importantForAccessibility="no"
                style={styles.art}
                defaultArtworkStyle={styles.defaultArt}
              />
            </View>
            <Text style={styles.title}>اكسب عملات مجانية</Text>
            <Text style={styles.description}>تستخدمها في شراء الكورسات</Text>
            <Pressable
              accessibilityRole="button"
              onPress={() => {
                onClose();
                onOpenWallet();
              }}
              style={({pressed}) => [
                styles.primary,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.primaryText}>اكتشف المهام</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              onPress={onClose}
              style={({pressed}) => [
                styles.secondary,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.secondaryText}>ليس الآن</Text>
            </Pressable>
          </View>
        </ScrollView>
      </View>
    </Modal>
  );
};

const styles = StyleSheet.create({
  overlay: {flex: 1, backgroundColor: Palette.overlay},
  content: {flexGrow: 1, alignItems: 'center', justifyContent: 'center'},
  card: {
    width: '100%',
    maxWidth: 400,
    minHeight: 320,
    alignItems: 'center',
    paddingHorizontal: 20,
    paddingTop: 46,
    paddingBottom: 9,
    borderRadius: 24,
    backgroundColor: '#142033',
    borderColor: '#2C3A50',
    borderWidth: 1,
  },
  close: {
    position: 'absolute',
    top: 3,
    left: 5,
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
  },
  closeText: {fontSize: 28, color: '#B4C0D2'},
  visual: {
    height: 104,
    width: '100%',
    overflow: 'hidden',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 5,
  },
  art: {width: '100%', maxWidth: 200, height: 104},
  // Same shipped-image identity/framing as welcome, never cropped uploads.
  defaultArt: {height: 200},
  title: {
    ...textDirection,
    textAlign: 'center',
    fontFamily: Fonts.extraBold,
    fontSize: 21,
    lineHeight: 35,
    color: Palette.text,
    marginTop: 3,
    marginBottom: 8,
  },
  description: {
    ...textDirection,
    textAlign: 'center',
    fontFamily: Fonts.regular,
    fontSize: 13,
    lineHeight: 24,
    color: '#B4C0D2',
    marginBottom: 18,
  },
  primary: {
    width: '100%',
    minHeight: Accessibility.minTouchTarget,
    paddingHorizontal: 14,
    paddingVertical: 10,
    borderRadius: 13,
    backgroundColor: Palette.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryText: {
    ...textDirection,
    textAlign: 'center',
    fontFamily: Fonts.bold,
    fontSize: 15,
    lineHeight: 24,
    color: '#FFFFFF',
  },
  secondary: {
    minHeight: Accessibility.minTouchTarget,
    paddingHorizontal: 16,
    paddingVertical: 10,
    justifyContent: 'center',
  },
  secondaryText: {
    ...textDirection,
    textAlign: 'center',
    fontFamily: Fonts.regular,
    fontSize: 13,
    lineHeight: 24,
    color: '#B4C0D2',
  },
  pressed: {opacity: 0.78},
});
