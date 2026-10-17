import React, {useEffect, useRef} from 'react';
import {
  AccessibilityInfo,
  Animated,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  type StyleProp,
  type ViewStyle,
} from 'react-native';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import {useReducedMotion} from '../../hooks/useReducedMotion';

export const SNACKBAR_DURATION_MS = 4_000;
const ENTER_MS = 180;
const EXIT_MS = 160;

type Props = {
  message: string;
  actionLabel: string;
  actionHint?: string;
  onAction: () => void;
  /** Called once the bar has timed out and left the screen. */
  onDismiss: () => void;
  duration?: number;
  style?: StyleProp<ViewStyle>;
};

/**
 * A short confirmation with one action, as in Instagram's "Saved" bar and the
 * Material snackbar. The host keeps a single slot and gives each message a new
 * key, so bars replace each other instead of stacking.
 */
export const ActionSnackbar = ({
  message,
  actionLabel,
  actionHint,
  onAction,
  onDismiss,
  duration = SNACKBAR_DURATION_MS,
  style,
}: Props) => {
  const reducedMotion = useReducedMotion();
  const reducedMotionRef = useRef(reducedMotion);
  reducedMotionRef.current = reducedMotion;
  const progress = useRef(new Animated.Value(reducedMotion ? 1 : 0)).current;
  const onDismissRef = useRef(onDismiss);
  onDismissRef.current = onDismiss;

  useEffect(() => {
    // Android reads the live region below; iOS needs an explicit announcement.
    if (Platform.OS === 'ios') {
      AccessibilityInfo.announceForAccessibility(message);
    }
    if (!reducedMotionRef.current) {
      Animated.timing(progress, {
        toValue: 1,
        duration: ENTER_MS,
        useNativeDriver: true,
      }).start();
    }
    const timer = setTimeout(() => {
      if (reducedMotionRef.current) {
        onDismissRef.current();
        return;
      }
      Animated.timing(progress, {
        toValue: 0,
        duration: EXIT_MS,
        useNativeDriver: true,
      }).start(({finished}) => {
        if (finished) onDismissRef.current();
      });
    }, duration);
    return () => {
      clearTimeout(timer);
      progress.stopAnimation();
    };
  }, [duration, message, progress]);

  return (
    <Animated.View
      accessibilityLiveRegion="polite"
      style={[
        styles.bar,
        style,
        {
          opacity: progress,
          transform: [
            {
              translateY: progress.interpolate({
                inputRange: [0, 1],
                outputRange: [8, 0],
              }),
            },
          ],
        },
      ]}>
      <Text maxFontSizeMultiplier={1.3} numberOfLines={2} style={styles.message}>
        {message}
      </Text>
      <Pressable
        accessibilityHint={actionHint}
        accessibilityLabel={actionLabel}
        accessibilityRole="button"
        hitSlop={4}
        onPress={onAction}
        style={({pressed}) => [styles.action, pressed && styles.pressed]}>
        <Text maxFontSizeMultiplier={1.3} style={styles.actionText}>
          {actionLabel}
        </Text>
      </Pressable>
    </Animated.View>
  );
};

const styles = StyleSheet.create({
  bar: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: Spacing.xs,
    minHeight: Accessibility.minTouchTarget,
    paddingStart: Spacing.md,
    paddingEnd: Spacing.xxs,
    borderRadius: Radius.md,
    backgroundColor: Palette.surfaceRaised,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: Palette.line,
  },
  message: {
    ...Type.bodyStrong,
    ...textDirection,
    flex: 1,
    minWidth: 0,
    color: Palette.text,
  },
  action: {
    minWidth: Accessibility.minTouchTarget,
    minHeight: Accessibility.minTouchTarget,
    paddingHorizontal: Spacing.sm,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.sm,
  },
  actionText: {
    ...Type.bodyStrong,
    color: Palette.primaryTintText,
  },
  pressed: {opacity: 0.72},
});
