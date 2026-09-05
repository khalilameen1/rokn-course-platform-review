import React, {useEffect, useState} from 'react';
import {
  Pressable,
  StyleSheet,
  Text,
  type NativeSyntheticEvent,
  type TextLayoutEventData,
  useWindowDimensions,
  View,
} from 'react-native';
import {textDirection} from '../../constants/designSystem';
import {
  formatArabicNumber,
  formatAuthoredDisplayText,
} from '../../constants/arabicFormatting';
import {Fonts} from '../../constants/styleConstants';
import {CourseReel} from './types';

interface FeedFooterProps {
  data: CourseReel;
  bottomInset?: number;
}

const FeedFooter = ({data, bottomInset = 0}: FeedFooterProps) => {
  const [expanded, setExpanded] = useState(false);
  const [canExpand, setCanExpand] = useState(false);
  const {height, fontScale} = useWindowDimensions();
  const compact = height < 620 || fontScale > 1.25;
  useEffect(() => {
    setExpanded(false);
    setCanExpand(false);
  }, [data.caption, data.id]);
  const captureCaptionLayout = (
    event: NativeSyntheticEvent<TextLayoutEventData>,
  ) => {
    if (!expanded) setCanExpand(event.nativeEvent.lines.length > 2);
  };
  return (
    <View
      pointerEvents="box-none"
      style={[
        styles.container,
        compact && styles.containerCompact,
        {bottom: (compact ? 34 : 48) + bottomInset},
      ]}>
      <View style={styles.numberPill}>
        <Text style={styles.numberText} maxFontSizeMultiplier={1.15}>
          المقطع {formatArabicNumber(data.reelNumber)}
        </Text>
      </View>
      <Text style={styles.title} numberOfLines={2}>
        {formatAuthoredDisplayText(data.title)}
      </Text>
      {!!data.caption && (
        <Pressable
          accessibilityRole={canExpand ? 'button' : undefined}
          accessibilityLabel={canExpand ? 'عرض الكابشن كاملًا' : undefined}
          disabled={!canExpand}
          hitSlop={{top: 10, bottom: 10, left: 4, right: 4}}
          onPress={() => setExpanded(value => !value)}
          style={styles.captionBlock}>
          <Text
            onTextLayout={captureCaptionLayout}
            style={styles.caption}
            numberOfLines={expanded ? (compact ? 4 : 6) : 2}>
            {formatAuthoredDisplayText(data.caption)}
          </Text>
          {!expanded && canExpand && (
            <Text style={styles.more} maxFontSizeMultiplier={1.15}>
              المزيد
            </Text>
          )}
        </Pressable>
      )}
    </View>
  );
};

export default FeedFooter;

const styles = StyleSheet.create({
  container: {
    position: 'absolute',
    left: 16,
    right: 82,
    zIndex: 20,
    alignItems: 'flex-end',
  },
  containerCompact: {
    left: 12,
    right: 70,
  },
  numberPill: {
    minHeight: 25,
    paddingHorizontal: 10,
    borderRadius: 13,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(5,10,18,.55)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.15)',
    marginBottom: 8,
  },
  numberText: {
    color: 'rgba(255,255,255,.82)',
    fontFamily: Fonts.medium,
    fontSize: 11,
  },
  title: {
    ...textDirection,
    alignSelf: 'stretch',
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 18,
    lineHeight: 27,
  },
  caption: {
    ...textDirection,
    alignSelf: 'stretch',
    marginTop: 4,
    color: 'rgba(255,255,255,.86)',
    fontFamily: Fonts.regular,
    fontSize: 13,
    lineHeight: 21,
  },
  more: {
    ...textDirection,
    alignSelf: 'stretch',
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 12,
    marginTop: 2,
  },
  captionBlock: {
    alignSelf: 'stretch',
  },
});
