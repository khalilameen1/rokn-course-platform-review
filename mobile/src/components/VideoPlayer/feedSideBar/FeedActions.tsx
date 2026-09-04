import React from 'react';
import {ActivityIndicator, Pressable, StyleSheet, Text, View} from 'react-native';
import Svg, {Path} from 'react-native-svg';
import {formatArabicNumber} from '../../../constants/arabicFormatting';
import {Accessibility} from '../../../constants/designSystem';
import {Fonts} from '../../../constants/styleConstants';

const ChatIcon = () => (
  <Svg width={28} height={28} viewBox="0 0 28 28">
    <Path
      d="M5.1 5.5h17.8c1.3 0 2.3 1 2.3 2.3v10c0 1.3-1 2.3-2.3 2.3h-8L8 24.5v-4.4H5.1c-1.3 0-2.3-1-2.3-2.3v-10c0-1.3 1-2.3 2.3-2.3Z"
      fill="none"
      stroke="#fff"
      strokeWidth={1.8}
      strokeLinejoin="round"
    />
    <Path
      d="M8.5 12.9h.1m5.3 0h.1m5.3 0h.1"
      stroke="#fff"
      strokeWidth={2.6}
      strokeLinecap="round"
    />
  </Svg>
);

const BookmarkIcon = ({filled}: {filled: boolean}) => (
  <Svg width={28} height={28} viewBox="0 0 28 28">
    <Path
      d="M7 5.2c0-1 .8-1.8 1.8-1.8h10.4c1 0 1.8.8 1.8 1.8v19.4l-7-4.3-7 4.3V5.2Z"
      fill={filled ? '#4B8EF7' : 'rgba(0,0,0,0)'}
      stroke="#fff"
      strokeWidth={1.8}
      strokeLinejoin="round"
    />
  </Svg>
);

export const AttachmentIcon = () => (
  <Svg width={27} height={27} viewBox="0 0 28 28">
    <Path
      d="m10.1 14.8 7.7-7.7a4 4 0 0 1 5.7 5.7L13.2 23.1a6 6 0 0 1-8.5-8.5L15.3 4"
      fill="none"
      stroke="#fff"
      strokeWidth={1.9}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const FeedActionButton = ({
  label,
  onPress,
  children,
  active,
  compact,
  disabled,
}: {
  label: string;
  onPress: () => void;
  children: React.ReactNode;
  active?: boolean;
  compact?: boolean;
  disabled?: boolean;
}) => (
  <Pressable
    accessibilityRole="button"
    accessibilityLabel={label}
    accessibilityState={{disabled, busy: disabled}}
    disabled={disabled}
    hitSlop={7}
    style={[
      styles.action,
      compact && styles.actionCompact,
      disabled && styles.actionDisabled,
    ]}
    onPress={onPress}>
    <View
      style={[
        styles.actionIcon,
        compact && styles.actionIconCompact,
        active && styles.actionIconActive,
      ]}>
      {children}
    </View>
    <Text
      maxFontSizeMultiplier={1.35}
      numberOfLines={1}
      style={styles.actionLabel}>
      {label}
    </Text>
  </Pressable>
);

type FeedActionsProps = {
  bottomInset: number;
  compact: boolean;
  currentReelNumber: number;
  isSaved: boolean;
  savePending: boolean;
  showAttachments: boolean;
  showChat: boolean;
  totalReels: number;
  onOpenAttachments: () => void;
  onOpenChat: () => void;
  onOpenIndex: () => void;
  onOpenSave: () => void;
};

const FeedActions = ({
  bottomInset,
  compact,
  currentReelNumber,
  isSaved,
  savePending,
  showAttachments,
  showChat,
  totalReels,
  onOpenAttachments,
  onOpenChat,
  onOpenIndex,
  onOpenSave,
}: FeedActionsProps) => (
  <View
    style={[
      styles.container,
      compact && styles.containerCompact,
      {bottom: (compact ? 42 : 56) + bottomInset},
    ]}>
    {showChat && (
      <FeedActionButton label="اسأل" compact={compact} onPress={onOpenChat}>
        <ChatIcon />
      </FeedActionButton>
    )}
    <FeedActionButton
      label={savePending ? 'جارٍ الحفظ' : isSaved ? 'محفوظ' : 'احفظ'}
      active={isSaved}
      compact={compact}
      disabled={savePending}
      onPress={onOpenSave}>
      {savePending ? (
        <ActivityIndicator color="#FFFFFF" size="small" />
      ) : (
        <BookmarkIcon filled={isSaved} />
      )}
    </FeedActionButton>
    {showAttachments && (
      <FeedActionButton
        label="ملفات"
        compact={compact}
        onPress={onOpenAttachments}>
        <AttachmentIcon />
      </FeedActionButton>
    )}
    <FeedActionButton label="الفهرس" compact={compact} onPress={onOpenIndex}>
      <Text style={styles.counter} maxFontSizeMultiplier={1.1}>
        {formatArabicNumber(currentReelNumber)}/
        {formatArabicNumber(totalReels)}
      </Text>
    </FeedActionButton>
  </View>
);

export default FeedActions;

const styles = StyleSheet.create({
  container: {
    position: 'absolute',
    right: 10,
    zIndex: 35,
    alignItems: 'center',
    gap: 17,
  },
  containerCompact: {gap: 10},
  action: {
    width: 70,
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
  },
  actionDisabled: {opacity: 0.62},
  actionCompact: {width: 62},
  actionIcon: {
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    borderRadius: 24,
    backgroundColor: 'rgba(4,8,13,.48)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.14)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  actionIconCompact: {
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    borderRadius: Accessibility.minTouchTarget / 2,
  },
  actionIconActive: {
    backgroundColor: 'rgba(35,111,232,.24)',
    borderColor: 'rgba(95,153,247,.45)',
  },
  actionLabel: {
    color: '#FFFFFF',
    fontFamily: Fonts.medium,
    fontSize: 11,
    marginTop: 4,
    textShadowColor: 'rgba(0,0,0,.9)',
    textShadowRadius: 5,
    width: '100%',
    textAlign: 'center',
  },
  counter: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 12,
    fontVariant: ['tabular-nums'],
  },
});
