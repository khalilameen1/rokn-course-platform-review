import React from 'react';
import {Pressable, Text, View} from 'react-native';
import {SectionHeading} from '../../components/ui/PremiumUI';
import {SkeletonBlock} from '../../components/ui/Skeleton';
import {CourseArtwork} from '../../components/ui/CourseArtwork';
import {formatArabicDisplayText} from '../../constants/arabicFormatting';
import {Radius} from '../../constants/designSystem';
import type {WatchHistoryItem} from '../../services/roknApi';
import {styles} from './styles';

type Props = {
  error: string;
  fresh: boolean;
  items: WatchHistoryItem[];
  largeText: boolean;
  loading: boolean;
  onResume: (item: WatchHistoryItem) => void;
};

export const WatchHistorySection = ({
  error,
  fresh,
  items,
  largeText,
  loading,
  onResume,
}: Props) => {
  if (!loading && !error && !items.length) return null;

  return (
    <>
      <SectionHeading
        eyebrow="ارجع إلى مقطع محدد"
        style={styles.section}
        title="آخر ما شاهدته"
      />
      {loading && !fresh ? (
        <View style={styles.historyList}>
          {[0, 1].map(item => (
            <View key={item} style={styles.historySkeletonRow}>
              <SkeletonBlock height={64} radius={Radius.md} width={96} />
              <View style={styles.historySkeletonCopy}>
                <SkeletonBlock height={16} width="86%" />
                <SkeletonBlock height={12} width="58%" />
              </View>
            </View>
          ))}
        </View>
      ) : error && !items.length ? (
        <View accessibilityRole="alert" style={styles.offlineNote}>
          <Text style={styles.offlineNoteText}>{error}</Text>
        </View>
      ) : (
        <>
          {!!error && (
            <View accessibilityRole="alert" style={styles.offlineNote}>
              <Text style={styles.offlineNoteText}>{error}</Text>
            </View>
          )}
          <View style={styles.historyList}>
            {items.map(item => (
              <Pressable
                accessibilityLabel={`استكمال ${item.lessonTitle}`}
                accessibilityRole="button"
                key={item.id}
                onPress={() => onResume(item)}
                style={({pressed}) => [
                  styles.historyRow,
                  pressed && styles.pressed,
                ]}>
                <CourseArtwork
                  fallback={require('../../assets/images/courseSliderBackground.jpg')}
                  source={
                    item.lessonThumbnail || item.courseImage
                      ? {uri: item.lessonThumbnail || item.courseImage}
                      : undefined
                  }
                  style={styles.historyThumb}
                />
                <View style={styles.historyCopy}>
                  <Text
                    numberOfLines={largeText ? 4 : 2}
                    style={styles.historyTitle}>
                    {formatArabicDisplayText(item.lessonTitle)}
                  </Text>
                  <Text
                    numberOfLines={largeText ? 2 : 1}
                    style={styles.historyCourse}>
                    {formatArabicDisplayText(item.courseTitle)}
                  </Text>
                  <View style={styles.historyProgressTrack}>
                    <View
                      style={[
                        styles.historyProgressFill,
                        {width: `${item.progress}%`},
                      ]}
                    />
                  </View>
                </View>
                <Text style={styles.historyAction}>أكمل</Text>
              </Pressable>
            ))}
          </View>
        </>
      )}
    </>
  );
};
