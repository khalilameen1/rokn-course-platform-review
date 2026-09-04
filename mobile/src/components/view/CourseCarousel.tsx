import React, {memo, useCallback} from 'react';
import {StyleSheet} from 'react-native';
import Carousel from 'react-native-reanimated-carousel';
import type {Course} from '../../types/Course';
import {Spacing, useResponsiveLayout} from '../../constants/designSystem';
import CarouselItem from './CarouselItem';

interface CourseCarouselProps {
  active?: boolean;
  data: Course[];
  onButtonPress: (course: Course) => void;
}

const CourseCarousel = memo<CourseCarouselProps>(
  ({active = true, data, onButtonPress}) => {
    const {contentWidth, isTablet, gutter} = useResponsiveLayout();
    const height = isTablet ? Math.min(400, contentWidth * 0.44) : 314;

    const renderItem = useCallback(
      ({item}: {item: Course}) => (
        <CarouselItem
          course={item}
          onButtonPress={() => onButtonPress(item)}
        />
      ),
      [onButtonPress],
    );

    if (!data.length) return null;

    return (
      <Carousel
        autoPlay={active && data.length > 1}
        autoPlayInterval={5500}
        data={data}
        height={height}
        loop={data.length > 1}
        mode="parallax"
        modeConfig={{
          parallaxScrollingScale: 0.93,
          parallaxScrollingOffset: gutter * 1.8,
        }}
        renderItem={renderItem}
        style={styles.carousel}
        width={contentWidth}
      />
    );
  },
);

const styles = StyleSheet.create({carousel: {marginBottom: Spacing.xl}});
CourseCarousel.displayName = 'CourseCarousel';
export default CourseCarousel;
