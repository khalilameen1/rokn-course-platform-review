import React from 'react';
import {Image, ImageSourcePropType, StyleSheet, Text, View} from 'react-native';
import {Colors, Fonts, PixelPerfect} from '../../constants/styleConstants';
import {rtlRowStyle, textDirection} from '../../constants/designSystem';

interface InstructorItemProps {
  image: ImageSourcePropType;
  name: string;
  description: string;
}

const InstructorItem = React.memo<InstructorItemProps>(
  ({image, name, description}) => {
    return (
      <View style={styles.instructorItem}>
        <Image source={image} style={styles.instructorImage} />
        <View style={styles.instructorInfo}>
          <Text style={styles.instructorName}>{name}</Text>
          <Text style={styles.instructorDescription}>{description}</Text>
        </View>
      </View>
    );
  },
  (prevProps, nextProps) => {
    return (
      prevProps.image === nextProps.image &&
      prevProps.name === nextProps.name &&
      prevProps.description === nextProps.description
    );
  },
);

InstructorItem.displayName = 'InstructorItem';

const styles = StyleSheet.create({
  instructorItem: {
    ...rtlRowStyle,
    alignItems: 'flex-start',
    gap: PixelPerfect(12),
    flex: 1,
    minWidth: 0, // Important for flex children to respect parent width
    maxWidth: '100%',
    overflow: 'hidden',
  },
  instructorImage: {
    width: PixelPerfect(40),
    height: PixelPerfect(40),
    borderRadius: PixelPerfect(20),
    flexShrink: 0, // Don't shrink the image
  },
  instructorInfo: {
    flex: 1,
    flexDirection: 'column',
    gap: PixelPerfect(4),
    minWidth: 0, // Important for flex children to respect parent width
  },
  instructorName: {
    ...textDirection,
    fontSize: PixelPerfect(14),
    fontFamily: Fonts.bold,
    color: Colors.white,
    flexShrink: 1,
  },
  instructorDescription: {
    ...textDirection,
    fontSize: PixelPerfect(12),
    fontFamily: Fonts.regular,
    color: '#848484',
  },
});

export default InstructorItem;
