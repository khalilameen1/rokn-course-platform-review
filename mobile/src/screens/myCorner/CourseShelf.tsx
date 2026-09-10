import React from 'react';
import {Pressable, ScrollView, Text, View} from 'react-native';
import {CourseArtwork} from '../../components/ui/CourseArtwork';
import {
  formatArabicDisplayText,
  formatAuthoredDisplayText,
} from '../../constants/arabicFormatting';
import type {LearningCourse} from '../../services/roknApi';
import {learningResumeTarget, type LearningResumeTarget} from './model';
import {styles} from './styles';
import {Spacing, useResponsiveLayout} from '../../constants/designSystem';

type Props = {
  error: string;
  largeText: boolean;
  learningOwnershipFresh: boolean;
  onOpenCourse: (courseId: string) => void;
  onResume: (target: LearningResumeTarget) => void;
  onRetry: () => void;
  orderedCourses: LearningCourse[];
};

export const CourseShelf = ({
  error,
  largeText,
  learningOwnershipFresh,
  onOpenCourse,
  onResume,
  onRetry,
  orderedCourses,
}: Props) => {
  const {contentWidth, fontScale, gutter, isTablet} = useResponsiveLayout();
  const cardWidth = Math.floor(
    Math.min(
      280,
      Math.max(1, (contentWidth - gutter * 2) * 0.8),
      Math.max(
        176 * Math.max(1, fontScale / 1.3),
        contentWidth * (isTablet ? 0.3 : 0.56),
      ),
    ),
  );

  return (
    <View style={styles.courseShelf}>
      {!!error && (
        <View accessibilityRole="alert" style={styles.offlineNote}>
          <Text style={styles.offlineNoteText}>{error}</Text>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="إعادة المحاولة"
            onPress={onRetry}
            style={styles.offlineRetry}>
            <Text style={styles.offlineRetryText}>إعادة المحاولة</Text>
          </Pressable>
        </View>
      )}
      <ScrollView
        accessibilityLabel="كورساتك، الأحدث نشاطًا أولًا"
        contentContainerStyle={styles.courseRail}
        decelerationRate="fast"
        horizontal
        nestedScrollEnabled
        snapToInterval={cardWidth + Spacing.sm}
        snapToAlignment="start"
        disableIntervalMomentum
        showsHorizontalScrollIndicator={false}>
        {orderedCourses.map(course => {
          const resumeTarget = learningResumeTarget(
            course,
            learningOwnershipFresh,
          );
          const progress = Math.max(0, Math.min(100, course.progress));
          const completed = progress >= 100;
          const progressLabel = completed
            ? 'مكتمل'
            : course.started
            ? progress > 0
              ? `اكتمل ${Math.round(progress)}٪`
              : 'بدأت التعلّم'
            : 'جاهز للبدء';

          return (
            <View
              key={course.id}
              style={[styles.courseCard, {width: cardWidth}]}>
              <Pressable
                accessibilityLabel={`عرض تفاصيل ${formatAuthoredDisplayText(
                  course.title,
                )}، ${formatArabicDisplayText(progressLabel)}`}
                accessibilityRole="button"
                onPress={() => onOpenCourse(course.id)}
                style={({pressed}) => [
                  styles.courseDetails,
                  pressed && styles.pressed,
                ]}>
                <CourseArtwork
                  fallback={require('../../assets/images/courseSliderBackground.jpg')}
                  source={course.imageUrl ? {uri: course.imageUrl} : undefined}
                  style={styles.courseCover}
                />
                <View style={styles.courseCopy}>
                  <Text
                    numberOfLines={largeText ? 4 : 2}
                    style={styles.courseTitle}>
                    {formatAuthoredDisplayText(course.title)}
                  </Text>
                  {(course.started || completed) && (
                    <Text
                      numberOfLines={largeText ? 3 : 2}
                      style={styles.nextLesson}>
                      {completed
                        ? 'راجع أي مقطع وقتما تريد'
                        : course.nextSectionTitle
                        ? formatAuthoredDisplayText(course.nextSectionTitle)
                        : course.lastLessonTitle
                        ? `أكمل بعد ${formatAuthoredDisplayText(
                            course.lastLessonTitle,
                          )}`
                        : 'أكمل من مكانك'}
                    </Text>
                  )}
                  <Text style={styles.progressLabel}>
                    {formatArabicDisplayText(progressLabel)}
                  </Text>
                  {course.started && (
                    <View
                      accessibilityRole="progressbar"
                      accessibilityValue={{
                        min: 0,
                        max: 100,
                        now: Math.round(progress),
                      }}
                      style={styles.progressTrack}>
                      <View
                        style={[styles.progressFill, {width: `${progress}%`}]}
                      />
                    </View>
                  )}
                </View>
              </Pressable>
              <View style={styles.resumeAction}>
                <Pressable
                  accessibilityLabel={`${
                    resumeTarget ? 'استكمال' : 'عرض تفاصيل'
                  } ${formatAuthoredDisplayText(course.title)}`}
                  accessibilityRole="button"
                  onPress={() => {
                    if (resumeTarget) onResume(resumeTarget);
                    else onOpenCourse(course.id);
                  }}
                  style={({pressed}) => [
                    styles.resumeButton,
                    pressed && styles.resumeButtonPressed,
                  ]}>
                  <Text style={styles.resumeButtonText}>
                    {resumeTarget
                      ? 'استكمل'
                      : completed
                      ? 'راجع الكورس'
                      : 'عرض الكورس'}
                  </Text>
                </Pressable>
              </View>
            </View>
          );
        })}
      </ScrollView>
    </View>
  );
};
