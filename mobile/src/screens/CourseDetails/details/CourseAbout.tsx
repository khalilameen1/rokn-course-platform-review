import React, {useState} from 'react';
import {Pressable, Text, View} from 'react-native';
import {RasterImage as Image} from '../../../components/ui/RasterImage';
import {AccordionArrowDown, AccordionArrowUp} from '../../../assets/SVG';
import {formatAuthoredDisplayText} from '../../../constants/arabicFormatting';
import type {CourseDetails} from '../../../services/roknApi';
import styles from './styles';

const CourseDescription = ({text}: {text: string}) => {
  const [expanded, setExpanded] = useState(false);
  const [overflows, setOverflows] = useState(false);
  const displayText = formatAuthoredDisplayText(text);
  return (
    <View style={styles.description}>
      {/* Measure at the actual width and OS font size, not a character count.
          The measuring copy is never exposed visually or to screen readers. */}
      <View
        accessible={false}
        accessibilityElementsHidden
        importantForAccessibility="no-hide-descendants"
        pointerEvents="none"
        style={styles.descriptionMeasure}>
        <Text
          style={styles.bodyCopy}
          onTextLayout={event =>
            setOverflows(event.nativeEvent.lines.length > 4)
          }>
          {displayText}
        </Text>
      </View>
      <Text style={styles.bodyCopy} numberOfLines={expanded ? undefined : 4}>
        {displayText}
      </Text>
      {overflows && (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={
            expanded ? 'عرض أقل من وصف الكورس' : 'عرض وصف الكورس كاملًا'
          }
          accessibilityState={{expanded}}
          style={styles.descriptionToggle}
          onPress={() => setExpanded(value => !value)}>
          <Text style={styles.descriptionToggleText}>
            {expanded ? 'عرض أقل' : 'عرض المزيد'}
          </Text>
          {expanded ? (
            <AccordionArrowUp accessible={false} />
          ) : (
            <AccordionArrowDown accessible={false} />
          )}
        </Pressable>
      )}
    </View>
  );
};

const InstructorDetails = ({details}: {details: CourseDetails}) => {
  const [expanded, setExpanded] = useState(false);
  return (
    <View style={styles.instructorDisclosure}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="عن المدرب"
        accessibilityState={{expanded}}
        style={styles.instructorToggle}
        onPress={() => setExpanded(value => !value)}>
        <Text style={styles.instructorToggleTitle}>عن المدرب</Text>
        {expanded ? (
          <AccordionArrowUp accessible={false} />
        ) : (
          <AccordionArrowDown accessible={false} />
        )}
      </Pressable>
      {expanded && (
        <View style={styles.instructorCard}>
          <Image
            source={
              details.instructorImage
                ? {uri: details.instructorImage}
                : require('../../../assets/images/default-avatar.png')
            }
            style={styles.instructorImage}
          />
          <View style={styles.instructorCopy}>
            <Text style={styles.instructorName}>
              {formatAuthoredDisplayText(details.instructor || '')}
            </Text>
            {!!details.instructorBio && (
              <Text style={styles.instructorBio}>
                {formatAuthoredDisplayText(details.instructorBio)}
              </Text>
            )}
          </View>
        </View>
      )}
    </View>
  );
};

export const CourseAbout = ({details}: {details?: CourseDetails | null}) => (
  <View style={styles.aboutWrap}>
    {!!details?.description && (
      <CourseDescription
        key={`${details.id}:${details.description}`}
        text={details.description}
      />
    )}
    {!!details?.instructor && (
      <InstructorDetails
        key={`${details.id}:${details.instructor}`}
        details={details}
      />
    )}
  </View>
);
