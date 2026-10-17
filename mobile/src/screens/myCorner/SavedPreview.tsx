import React from 'react';
import {Text, View} from 'react-native';
import {SectionHeading} from '../../components/ui/PremiumUI';
import type {SavedLesson} from '../../services/roknApi';
import {SavedLessonRow} from '../savedLibrary/SavedLessonRow';
import {savedLibraryStyles} from '../savedLibrary/styles';
import {styles} from './styles';
import type {SavedPreviewStatus} from './useSavedPreview';

type Props = {
  hasCourses: boolean;
  lessons: SavedLesson[];
  onOpenAll: () => void;
  onOpenLesson: (lesson: SavedLesson) => void;
  status: SavedPreviewStatus;
};

/**
 * Saved lessons live with the learner's courses, like Udemy's My learning
 * lists. Shown only after the first read settles so nothing above it moves.
 */
export const SavedPreview = ({
  hasCourses,
  lessons,
  onOpenAll,
  onOpenLesson,
  status,
}: Props) => {
  if (status === 'loading') return null;
  const empty = status === 'ready' && !lessons.length;
  // A learner with no courses and nothing saved already sees the start hint
  // above; a second empty block would only repeat it.
  if (empty && !hasCourses) return null;

  return (
    <View>
      <SectionHeading
        actionLabel="عرض الكل"
        onAction={onOpenAll}
        style={styles.section}
        title="محفوظاتك"
      />
      {lessons.length ? (
        <View style={styles.savedCard}>
          {lessons.map((lesson, index) => (
            <View key={lesson.id}>
              <SavedLessonRow
                item={lesson}
                onPress={() => onOpenLesson(lesson)}
                style={styles.savedRow}
              />
              {index < lessons.length - 1 && (
                <View style={savedLibraryStyles.divider} />
              )}
            </View>
          ))}
        </View>
      ) : empty ? (
        <View style={styles.savedCard}>
          <Text style={styles.savedHint}>احفظ أي درس وهتلاقيه هنا</Text>
        </View>
      ) : null}
    </View>
  );
};
