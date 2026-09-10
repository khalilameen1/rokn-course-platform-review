import React, {memo} from 'react';
import type {Course} from '../../types/Course';
import CarouselItem from './CarouselItem';

interface CourseCarouselProps {
  active?: boolean;
  data: Course[];
  onButtonPress: (course: Course) => void;
}

// Home has one editorial feature, not a second scrolling surface.
const CourseCarousel = memo<CourseCarouselProps>(({data, onButtonPress}) => {
  const course = data[0];
  if (!course) return null;
  return (
    <CarouselItem course={course} onButtonPress={() => onButtonPress(course)} />
  );
});

CourseCarousel.displayName = 'CourseCarousel';
export default CourseCarousel;
