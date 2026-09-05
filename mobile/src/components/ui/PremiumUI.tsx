import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleProp,
  StyleSheet,
  Text,
  View,
  ViewStyle,
} from 'react-native';
import {
  Accessibility,
  flexibleTextColumn,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  rtlStartAlignment,
  textDirection,
  useResponsiveLayout,
} from '../../constants/designSystem';
import {
  formatArabicDisplayText,
  formatAuthoredDisplayText,
} from '../../constants/arabicFormatting';
import {learnerFacingText} from '../../utils/errorPayload';

export const ResponsiveFrame = ({
  children,
  style,
  padded = true,
}: {
  children: React.ReactNode;
  style?: StyleProp<ViewStyle>;
  padded?: boolean;
}) => {
  const {contentWidth, gutter} = useResponsiveLayout();
  return (
    <View
      style={[
        styles.frame,
        {maxWidth: contentWidth, paddingHorizontal: padded ? gutter : 0},
        style,
      ]}>
      {children}
    </View>
  );
};

export const PremiumCard = ({
  children,
  style,
  accessibilityLabel,
}: {
  children: React.ReactNode;
  style?: StyleProp<ViewStyle>;
  accessibilityLabel?: string;
}) => (
  <View accessibilityLabel={accessibilityLabel} style={[styles.card, style]}>
    {children}
  </View>
);

export const SectionHeading = ({
  title,
  eyebrow,
  actionLabel,
  onAction,
  style,
}: {
  title: string;
  eyebrow?: string;
  actionLabel?: string;
  onAction?: () => void;
  style?: StyleProp<ViewStyle>;
}) => (
  <View style={[styles.headingRow, style]}>
    <View style={styles.headingCopy}>
      {!!eyebrow && (
        <Text style={styles.eyebrow}>{formatArabicDisplayText(eyebrow)}</Text>
      )}
      <Text accessibilityRole="header" style={styles.headingTitle}>
        {formatAuthoredDisplayText(title)}
      </Text>
    </View>
    {!!actionLabel && !!onAction && (
      <Pressable
        accessibilityRole="button"
        hitSlop={8}
        onPress={onAction}
        style={({pressed}) => [styles.textAction, pressed && styles.pressed]}>
        <Text style={styles.textActionLabel}>
          {formatArabicDisplayText(actionLabel)}
        </Text>
      </Pressable>
    )}
  </View>
);

export const StatusView = ({
  state = 'empty',
  title,
  description,
  actionLabel,
  onAction,
}: {
  state?: 'empty' | 'loading' | 'error';
  title: string;
  description?: string;
  actionLabel?: string;
  onAction?: () => void;
}) => {
  const visibleTitle =
    state === 'error'
      ? learnerFacingText(title, 'تعذّر إكمال الطلب')
      : formatArabicDisplayText(title);
  const visibleDescription = description
    ? state === 'error'
      ? learnerFacingText(description, 'حاول مرة أخرى')
      : formatArabicDisplayText(description)
    : '';
  const visibleAction = actionLabel
    ? state === 'error'
      ? learnerFacingText(actionLabel, 'حاول مرة أخرى')
      : formatArabicDisplayText(actionLabel)
    : '';

  return (
    <View
      accessibilityLiveRegion={state === 'loading' ? 'polite' : undefined}
      accessibilityRole={state === 'error' ? 'alert' : undefined}
      style={styles.status}>
      {state === 'loading' ? (
        <ActivityIndicator color={Palette.primary} size="small" />
      ) : (
        <View
          style={[
            styles.statusMark,
            state === 'error' && styles.statusMarkError,
          ]}>
          <View style={styles.statusMarkInner} />
        </View>
      )}
      <Text style={styles.statusTitle}>{visibleTitle}</Text>
      {!!visibleDescription && (
        <Text style={styles.statusDescription}>{visibleDescription}</Text>
      )}
      {!!visibleAction && !!onAction && (
        <Pressable
          accessibilityRole="button"
          onPress={onAction}
          style={({pressed}) => [
            styles.statusAction,
            pressed && styles.pressed,
          ]}>
          <Text style={styles.statusActionLabel}>{visibleAction}</Text>
        </Pressable>
      )}
    </View>
  );
};

export const MetaPill = ({
  label,
  tone = 'neutral',
  style,
}: {
  label: string;
  tone?: 'neutral' | 'primary' | 'coin' | 'success';
  style?: StyleProp<ViewStyle>;
}) => (
  <View
    style={[
      styles.pill,
      tone === 'primary' && styles.primaryPill,
      tone === 'coin' && styles.coinPill,
      tone === 'success' && styles.successPill,
      style,
    ]}>
    <Text
      style={[
        styles.pillLabel,
        tone === 'primary' && styles.primaryPillLabel,
        tone === 'coin' && styles.coinPillLabel,
        tone === 'success' && styles.successPillLabel,
      ]}>
      {formatArabicDisplayText(label)}
    </Text>
  </View>
);

export const Divider = () => <View style={styles.divider} />;

const styles = StyleSheet.create({
  frame: {width: '100%', alignSelf: 'center'},
  card: {
    backgroundColor: Palette.surface,
    borderColor: Palette.lineSoft,
    borderWidth: 1,
    borderRadius: Radius.lg,
    overflow: 'hidden',
  },
  headingRow: {
    width: '100%',
    minHeight: Accessibility.minTouchTarget,
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: Spacing.sm,
  },
  headingCopy: {
    ...flexibleTextColumn,
    flex: 1,
    direction: 'rtl',
    alignItems: 'stretch',
  },
  eyebrow: {
    ...Type.caption,
    ...textDirection,
    color: Palette.primary,
    marginBottom: Spacing.xxs,
  },
  headingTitle: {
    ...Type.section,
    ...textDirection,
    color: Palette.text,
    width: '100%',
    alignSelf: 'stretch',
  },
  textAction: {
    minHeight: Accessibility.minTouchTarget,
    justifyContent: 'center',
    paddingHorizontal: Spacing.sm,
    marginEnd: -Spacing.sm,
    borderRadius: Radius.sm,
    flexShrink: 0,
    maxWidth: '44%',
  },
  textActionLabel: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
  },
  pressed: {opacity: 0.72, transform: [{scale: 0.985}]},
  status: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: Spacing.xl,
    paddingVertical: Spacing.section,
  },
  statusMark: {
    width: 46,
    height: 46,
    borderRadius: 23,
    backgroundColor: Palette.primarySoft,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: Spacing.md,
  },
  statusMarkError: {backgroundColor: 'rgba(240,100,105,0.12)'},
  statusMarkInner: {
    width: 12,
    height: 12,
    borderRadius: 6,
    backgroundColor: Palette.primary,
  },
  statusTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
    textAlign: 'center',
  },
  statusDescription: {
    ...Type.caption,
    writingDirection: 'rtl',
    color: Palette.textMuted,
    textAlign: 'center',
    marginTop: Spacing.xs,
    maxWidth: 440,
  },
  statusAction: {
    minHeight: Accessibility.minTouchTarget,
    justifyContent: 'center',
    paddingHorizontal: Spacing.lg,
    borderRadius: Radius.pill,
    backgroundColor: Palette.primarySoft,
    marginTop: Spacing.md,
  },
  statusActionLabel: {...Type.bodyStrong, color: '#8BB5FF'},
  pill: {
    alignSelf: rtlStartAlignment,
    paddingHorizontal: Spacing.sm,
    paddingVertical: Spacing.xxs,
    borderRadius: Radius.pill,
    backgroundColor: 'rgba(255,255,255,0.08)',
  },
  primaryPill: {backgroundColor: Palette.primarySoft},
  coinPill: {backgroundColor: Palette.coinSoft},
  successPill: {backgroundColor: 'rgba(72,185,138,0.12)'},
  pillLabel: {...Type.caption, ...textDirection, color: Palette.textMuted},
  primaryPillLabel: {color: '#8BB5FF'},
  coinPillLabel: {color: '#F1CB76'},
  successPillLabel: {color: '#79D6AE'},
  divider: {
    height: StyleSheet.hairlineWidth,
    backgroundColor: Palette.lineSoft,
  },
});
