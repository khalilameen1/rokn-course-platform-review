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
  onLoadMore?: () => void;
}

const CoursesSection = memo<CoursesSectionProps>(
  ({data, title = 'كورسات مختارة لك', onCoursePress, onLoadMore}) => {
    const {gutter} = useResponsiveLayout();
    const renderCourse = useCallback(
      ({item}: ListRenderItemInfo<Course>) => (
        <CourseCard item={item} onPress={onCoursePress} sectionTitle={title} />
      ),
      [onCoursePress, title],
    );

    if (!data.length) return null;

    return (
      <View style={styles.sectionContainer}>
        <View style={[styles.headingWrap, {paddingHorizontal: gutter}]}>
          <SectionHeading title={title} style={styles.heading} />
        </View>
        <FlatList
          accessibilityRole="list"
          data={data}
          contentContainerStyle={{
            ...rtlRowStyle,
            gap: Spacing.sm,
            paddingHorizontal: gutter,
            paddingTop: Spacing.xs,
          }}
          horizontal
          initialNumToRender={5}
          keyExtractor={item => item.id}
          maxToRenderPerBatch={6}
          onEndReached={onLoadMore}
          onEndReachedThreshold={0.5}
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
    previous.onLoadMore === next.onLoadMore &&
    previous.data.length === next.data.length &&
    previous.data.every((item, index) => item === next.data[index]),
);

const styles = StyleSheet.create({
  sectionContainer: {marginBottom: Spacing.xl},
  headingWrap: {width: '100%', direction: 'rtl', alignItems: 'stretch'},
  heading: {minHeight: 0},
});

CoursesSection.displayName = 'CoursesSection';
export default CoursesSection;
