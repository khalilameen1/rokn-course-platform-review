import React from 'react';
import {Pressable, Text, View} from 'react-native';
import {PlayInSavedVideosIcon} from '../../assets/SVG';
import {RasterImage as Image} from '../../components/ui/RasterImage';
import {
  formatAuthoredDisplayText,
  toArabicDigits,
} from '../../constants/arabicFormatting';
import type {SavedLesson} from '../../services/roknApi';
import {savedLibraryStyles as styles} from './styles';

type Props = {
  item: SavedLesson;
  onPress: () => void;
  /** Row-level action, such as removal in the full library. */
  trailing?: React.ReactNode;
};

/** One saved lesson row in the full library. */
export const SavedLessonRow = ({item, onPress, trailing}: Props) => (
  <Pressable
    accessibilityLabel={`تشغيل ${item.title}`}
    accessibilityRole="button"
    onPress={onPress}
    style={({pressed}) => [styles.row, pressed && styles.pressed]}>
    <View style={styles.thumbWrap}>
      <Image
        progressiveRenderingEnabled
        resizeMethod="resize"
        source={
          item.imageUrl
            ? {uri: item.imageUrl}
            : require('../../assets/images/courseSliderBackground.jpg')
        }
        style={styles.thumb}
      />
      <View style={styles.playMark}>
        <PlayInSavedVideosIcon width={28} height={28} />
      </View>
    </View>
    <View style={styles.copy}>
      <Text style={styles.title}>{formatAuthoredDisplayText(item.title)}</Text>
      <Text numberOfLines={1} style={styles.course}>
        {formatAuthoredDisplayText(item.courseTitle)}
      </Text>
      <Text style={styles.duration}>{toArabicDigits(item.duration)}</Text>
    </View>
    {trailing}
  </Pressable>
);
