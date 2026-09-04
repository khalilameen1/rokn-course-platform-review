import React, {memo, useCallback} from 'react';
import {FlatList, ListRenderItemInfo, StyleSheet, View} from 'react-native';
import {
  Spacing,
  rtlRowStyle,
  useResponsiveLayout,
} from '../../constants/designSystem';
import CourseCard, {Course} from './CourseCard';
import {SectionHeading} from '../ui/PremiumUI';

interface CoursesSectionProps {
  data: Course[];
  title?: string;
  onCoursePress: (course: Course) => void;
}

const CoursesSection = memo<CoursesSectionProps>(
  ({data, title = 'كورسات مختارة لك', onCoursePress}) => {
    const {gutter} = useResponsiveLayout();
    const renderCourse = useCallback(
      ({item}: ListRenderItemInfo<Course>) => (
        <CourseCard item={item} onPress={onCoursePress} />
      ),
      [onCoursePress],
    );

    if (!data.length) return null;

    return (
      <View style={styles.sectionContainer}>
        <View style={[styles.headingWrap, {paddingHorizontal: gutter}]}>
          <SectionHeading title={title} />
        </View>
        <FlatList
          accessibilityRole="list"
          data={data}
          contentContainerStyle={{
            ...rtlRowStyle,
            gap: Spacing.sm,
            paddingHorizontal: gutter,
            paddingTop: Spacing.sm,
          }}
          horizontal
          initialNumToRender={5}
          keyExtractor={item => item.id}
          maxToRenderPerBatch={6}
          removeClippedSubviews
          renderItem={renderCourse}
          showsHorizontalScrollIndicator={false}
          windowSize={5}
        />
      </View>
    );
  },
  (previous, next) =>
    previous.title === next.title &&
    previous.onCoursePress === next.onCoursePress &&
    previous.data.length === next.data.length &&
    previous.data.every((item, index) => item === next.data[index]),
);

const styles = StyleSheet.create({
  sectionContainer: {marginBottom: Spacing.xl},
  headingWrap: {width: '100%', direction: 'rtl', alignItems: 'stretch'},
});

CoursesSection.displayName = 'CoursesSection';
export default CoursesSection;
