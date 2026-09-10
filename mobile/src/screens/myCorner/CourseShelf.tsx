import React from 'react';
import {Pressable, Text, View} from 'react-native';
import {SectionHeading} from '../../components/ui/PremiumUI';
import {CourseArtwork} from '../../components/ui/CourseArtwork';
import {
  formatArabicDisplayText,
  formatAuthoredDisplayText,
} from '../../constants/arabicFormatting';
import type {LearningCourse} from '../../services/roknApi';
import {learningResumeTarget, type LearningResumeTarget} from './model';
import {styles} from './styles';

type Props = {
  error: string;
  hasActiveCourses: boolean;
  largeText: boolean;
  learningOwnershipFresh: boolean;
  onOpenCourse: (courseId: string) => void;
  onResume: (target: LearningResumeTarget) => void;
  onRetry: () => void;
  orderedCourses: LearningCourse[];
  primaryResumeId?: string;
};

export const CourseShelf = ({
  error,
  hasActiveCourses,
  largeText,
  learningOwnershipFresh,
  onOpenCourse,
  onResume,
  onRetry,
  orderedCourses,
  primaryResumeId,
}: Props) => (
  <View style={styles.courseGrid}>
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
    {orderedCourses.map((course, index) => {
      const resumeTarget = learningResumeTarget(course, learningOwnershipFresh);
      const isPrimaryResume =
        course.id === primaryResumeId && Boolean(resumeTarget);
      const progress = Math.max(0, Math.min(100, course.progress));
      const completed = progress >= 100;
      const startsCompletedShelf =
        hasActiveCourses &&
        completed &&
        (index === 0 || orderedCourses[index - 1].progress < 100);
      const progressLabel = completed
        ? 'مكتمل'
        : course.started
        ? progress > 0
          ? `اكتمل ${Math.round(progress)}٪`
          : 'بدأت التعلّم'
        : 'جاهز للبدء';

      return (
        <React.Fragment key={course.id}>
          {startsCompletedShelf && (
            <SectionHeading style={styles.completedHeading} title="أنهيتها" />
          )}
          <View
            style={[
              styles.courseCard,
              isPrimaryResume && styles.primaryResumeCard,
            ]}>
            <Pressable
              accessibilityLabel={`عرض تفاصيل ${formatAuthoredDisplayText(
                course.title,
              )}، ${formatArabicDisplayText(progressLabel)}`}
              accessibilityRole="button"
              onPress={() => onOpenCourse(course.id)}
              style={({pressed}) => [
                styles.courseDetails,
                (isPrimaryResume || largeText) && styles.courseDetailsStacked,
                pressed && styles.pressed,
              ]}>
              <CourseArtwork
                fallback={require('../../assets/images/courseSliderBackground.jpg')}
                source={course.imageUrl ? {uri: course.imageUrl} : undefined}
                style={
                  isPrimaryResume
                    ? styles.primaryCourseCover
                    : largeText
                    ? styles.largeTextCourseCover
                    : styles.courseCover
                }
              />
              <View
                style={[
                  styles.courseCopy,
                  isPrimaryResume && styles.primaryCourseCopy,
                ]}>
                <Text
                  numberOfLines={largeText ? 4 : 2}
                  style={[
                    styles.courseTitle,
                    isPrimaryResume && styles.primaryCourseTitle,
                  ]}>
                  {formatAuthoredDisplayText(course.title)}
                </Text>
                {(course.started || completed) && (
                  <Text style={styles.nextLesson}>
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
            {resumeTarget && (
              <View
                style={
                  isPrimaryResume
                    ? styles.primaryResumeAction
                    : [
                        styles.resumeAction,
                        !largeText && styles.compactResumeAction,
                      ]
                }>
                <Pressable
                  accessibilityLabel={`استكمال ${formatAuthoredDisplayText(
                    course.title,
                  )}`}
                  accessibilityRole="button"
                  onPress={() => onResume(resumeTarget)}
                  style={({pressed}) => [
                    styles.resumeButton,
                    !isPrimaryResume && styles.secondaryResumeButton,
                    pressed && styles.resumeButtonPressed,
                  ]}>
                  <Text
                    style={[
                      styles.resumeButtonText,
                      !isPrimaryResume && styles.secondaryResumeText,
                    ]}>
                    استكمل
                  </Text>
                </Pressable>
              </View>
            )}
          </View>
        </React.Fragment>
      );
    })}
  </View>
);
