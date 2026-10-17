import React from 'react';
import {Pressable, ScrollView, Text, View} from 'react-native';
import {BookmarkIcon} from '../../components/ui/BookmarkIcon';
import {CourseArtwork} from '../../components/ui/CourseArtwork';
import {SectionHeading} from '../../components/ui/PremiumUI';
import {
  formatArabicCount,
  formatAuthoredDisplayText,
} from '../../constants/arabicFormatting';
import {
  Palette,
  Spacing,
  useResponsiveLayout,
} from '../../constants/designSystem';
import {styles} from './styles';
import type {SavedCollection, SavedPreviewStatus} from './useSavedPreview';

type Props = {
  collections: SavedCollection[];
  hasCourses: boolean;
  onOpenAll: () => void;
  onOpenCollection: (folderId: string) => void;
  status: SavedPreviewStatus;
};

export const formatSavedLessonsCount = (count: number) =>
  formatArabicCount(count, {
    zero: 'مفيش دروس',
    one: 'درس واحد',
    two: 'درسين',
    few: 'دروس',
    many: 'درس',
    other: 'درس',
  });

/**
 * Saved lists as cards, like the Saved home in Instagram and Facebook: cover,
 * name and count, each opening its list. Shown only after the first read
 * settles so nothing above it moves.
 */
export const SavedPreview = ({
  collections,
  hasCourses,
  onOpenAll,
  onOpenCollection,
  status,
}: Props) => {
  const {largeText, railCardWidth} = useResponsiveLayout();
  if (status === 'loading') return null;
  // An empty default list alone is not a collection the learner made.
  const empty =
    status === 'ready' &&
    !collections.some(
      collection => !collection.watchLater || collection.lessonsCount !== 0,
    );
  // A learner with no courses and nothing saved already sees the start hint
  // above; a second empty block would only repeat it.
  if (empty && !hasCourses) return null;
  const cardWidth = Math.floor(railCardWidth);

  return (
    <View>
      <SectionHeading
        actionLabel="عرض الكل"
        onAction={onOpenAll}
        style={styles.section}
        title="محفوظاتك"
      />
      {empty ? (
        <View style={styles.savedCard}>
          <Text style={styles.savedHint}>احفظ أي درس وهتلاقيه هنا</Text>
        </View>
      ) : collections.length ? (
        <ScrollView
          accessibilityLabel="قوائمك"
          contentContainerStyle={styles.courseRail}
          decelerationRate="fast"
          disableIntervalMomentum
          horizontal
          nestedScrollEnabled
          showsHorizontalScrollIndicator={false}
          snapToAlignment="start"
          snapToInterval={cardWidth + Spacing.sm}
          style={styles.courseShelf}>
          {collections.map(collection => {
            const name = formatAuthoredDisplayText(collection.name);
            const count =
              collection.lessonsCount === undefined
                ? ''
                : formatSavedLessonsCount(collection.lessonsCount);
            return (
              <Pressable
                accessibilityLabel={count ? `${name}، ${count}` : name}
                accessibilityRole="button"
                key={collection.id}
                onPress={() => onOpenCollection(collection.id)}
                style={({pressed}) => [
                  styles.courseCard,
                  {width: cardWidth},
                  pressed && styles.pressed,
                ]}>
                {collection.coverUrl ? (
                  <CourseArtwork
                    fallback={require('../../assets/images/courseSliderBackground.jpg')}
                    source={{uri: collection.coverUrl}}
                    style={styles.courseCover}
                  />
                ) : (
                  <View
                    importantForAccessibility="no-hide-descendants"
                    style={styles.collectionTile}>
                    <BookmarkIcon color={Palette.textMuted} size={32} />
                  </View>
                )}
                <View style={styles.courseCopy}>
                  <Text
                    numberOfLines={largeText ? 3 : 2}
                    style={styles.courseTitle}>
                    {name}
                  </Text>
                  {!!count && <Text style={styles.nextLesson}>{count}</Text>}
                </View>
              </Pressable>
            );
          })}
        </ScrollView>
      ) : null}
    </View>
  );
};
