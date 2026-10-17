import React, {useState} from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import Svg, {Path} from 'react-native-svg';
import {formatArabicNumber} from '../../../constants/arabicFormatting';
import {Accessibility, Type} from '../../../constants/designSystem';
import {Fonts} from '../../../constants/styleConstants';
import {BookmarkIcon} from '../../ui/BookmarkIcon';

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
  onLongPress,
  children,
  active,
  compact,
  disabled,
}: {
  label: string;
  onPress: () => void;
  onLongPress?: () => void;
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
    onLongPress={onLongPress}
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
  /**
   * Bottom edge of a letterboxed picture inside the frame. When the rail would
   * cover the picture it tightens into its compact spacing so it sits in the
   * letterbox area below the picture whenever that area is tall enough.
   */
  contentBottom?: number;
  currentReelNumber: number;
  isSaved: boolean;
  savePending: boolean;
  showAttachments: boolean;
  totalReels: number;
  onOpenAttachments: () => void;
  onOpenChat: () => void;
  onOpenIndex: () => void;
  /** Saves to the default list, or opens the lists when already saved. */
  onPressSave: () => void;
  /** Opens the lists directly, for choosing a list before saving. */
  onLongPressSave: () => void;
};

const FeedActions = ({
  bottomInset,
  compact: compactLayout,
  contentBottom,
  currentReelNumber,
  isSaved,
  savePending,
  showAttachments,
  totalReels,
  onOpenAttachments,
  onOpenChat,
  onOpenIndex,
  onPressSave,
  onLongPressSave,
}: FeedActionsProps) => {
  // Latched per picture band so the switch cannot oscillate with its layout.
  const [avoidedBand, setAvoidedBand] = useState<number | null>(null);
  const compact =
    compactLayout ||
    (contentBottom !== undefined && avoidedBand === contentBottom);
  return (
    <View
      onLayout={event => {
        if (
          contentBottom !== undefined &&
          !compact &&
          event.nativeEvent.layout.y < contentBottom
        ) {
          setAvoidedBand(contentBottom);
        }
      }}
      style={[
        styles.container,
        compact && styles.containerCompact,
        {bottom: (compact ? 42 : 56) + bottomInset},
      ]}>
      <FeedActionButton label="اسأل" compact={compact} onPress={onOpenChat}>
        <ChatIcon />
      </FeedActionButton>
      <FeedActionButton
        label={savePending ? 'بيتحفظ' : isSaved ? 'محفوظ' : 'احفظ'}
        active={isSaved}
        compact={compact}
        disabled={savePending}
        onLongPress={onLongPressSave}
        onPress={onPressSave}>
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
};

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
    backgroundColor: 'rgba(7,11,18,.86)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  actionIconCompact: {
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    borderRadius: Accessibility.minTouchTarget / 2,
  },
  actionIconActive: {
    backgroundColor: 'rgba(20,47,89,.96)',
  },
  // Plain shadowed labels under the buttons, as TikTok/Reels/Shorts rails do:
  // no second dark plate wider than the button, still legible on any frame.
  actionLabel: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: Type.caption.fontSize,
    marginTop: 3,
    textShadowColor: 'rgba(0,0,0,.85)',
    textShadowOffset: {width: 0, height: 1},
    textShadowRadius: 4,
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
