import React from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import {MetaPill, SectionHeading} from '../../components/ui/PremiumUI';
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
  orderedCourses: LearningCourse[];
  primaryResumeId?: string;
};

const courseStatus = (
  course: LearningCourse,
  isPrimaryResume: boolean,
): string => {
  if (course.progress >= 100) return 'مكتمل';
  if (isPrimaryResume) return 'أكمل الآن';
  if (course.started) return 'قيد التعلّم';
  return 'جاهز للبدء';
};

export const CourseShelf = ({
  error,
  hasActiveCourses,
  largeText,
  learningOwnershipFresh,
  onOpenCourse,
  onResume,
  orderedCourses,
  primaryResumeId,
}: Props) => (
  <View style={styles.courseGrid}>
    {!!error && (
      <View accessibilityRole="alert" style={styles.offlineNote}>
        <Text style={styles.offlineNoteText}>{error}</Text>
      </View>
    )}
    {orderedCourses.map((course, index) => {
      const hasProgress = course.started;
      const resumeTarget = learningResumeTarget(course, learningOwnershipFresh);
      const isPrimaryResume =
        course.id === primaryResumeId && Boolean(resumeTarget);
      const startsCompletedShelf =
        hasActiveCourses &&
        course.progress >= 100 &&
        (index === 0 || orderedCourses[index - 1].progress < 100);

      return (
        <React.Fragment key={course.id}>
          {startsCompletedShelf && (
            <SectionHeading
              eyebrow="للرجوع والمراجعة"
              style={styles.completedHeading}
              title="أنهيتها"
            />
          )}
          <Pressable
            accessibilityLabel={`عرض تفاصيل ${formatAuthoredDisplayText(course.title)}${
              course.progress > 0
                ? formatArabicDisplayText(`، اكتمل ${Math.round(course.progress)}٪`)
                : ''
            }`}
            accessibilityRole="button"
            onPress={() => onOpenCourse(course.id)}
            style={({pressed}) => [
              styles.courseCard,
              isPrimaryResume && styles.primaryResumeCard,
              pressed && styles.pressed,
            ]}>
            <CourseArtwork
              fallback={require('../../assets/images/courseSliderBackground.jpg')}
              source={course.imageUrl ? {uri: course.imageUrl} : undefined}
              style={styles.courseCover}
            />
            <LinearGradient
              colors={[
                'rgba(5,8,13,.08)',
                'rgba(5,8,13,.45)',
                'rgba(5,8,13,.96)',
              ]}
              locations={[0, 0.52, 1]}
              pointerEvents="none"
              style={StyleSheet.absoluteFill}
            />
            <View style={styles.courseCopy}>
              <MetaPill
                label={courseStatus(course, isPrimaryResume)}
                tone="primary"
              />
              <Text
                numberOfLines={largeText ? 4 : 2}
                style={styles.courseTitle}>
                {formatAuthoredDisplayText(course.title)}
              </Text>
              <Text style={styles.nextLesson}>
                {course.progress >= 100
                  ? 'راجع أي مقطع وقتما تريد'
                  : hasProgress
                  ? course.nextSectionTitle
                    ? `التالي\n${course.nextSectionTitle}`
                    : course.lastLessonTitle
                    ? `أكمل بعد ${course.lastLessonTitle}`
                    : 'أكمل من مكانك'
                  : 'ابدأ بالمقطع الأول'}
              </Text>
              <View style={styles.progressTrack}>
                <View
                  style={[styles.progressFill, {width: `${course.progress}%`}]}
                />
              </View>
              <Text style={styles.progressLabel}>
                {course.started
                  ? formatArabicDisplayText(
                      course.progress > 0
                        ? `اكتمل ${Math.round(course.progress)}٪`
                        : 'بدأت التعلّم',
                    )
                  : 'جاهز للبدء'}
              </Text>
              {resumeTarget && (
                <Pressable
                  accessibilityLabel={`${hasProgress ? 'استكمال' : 'بدء'} ${formatAuthoredDisplayText(course.title)}`}
                  accessibilityRole="button"
                  onPress={event => {
                    event.stopPropagation();
                    onResume(resumeTarget);
                  }}
                  style={({pressed}) => [
                    styles.resumeButton,
                    pressed && styles.resumeButtonPressed,
                  ]}>
                  <Text style={styles.resumeButtonText}>
                    {hasProgress ? 'استكمل' : 'ابدأ'}
                  </Text>
                </Pressable>
              )}
            </View>
          </Pressable>
        </React.Fragment>
      );
    })}
  </View>
);
