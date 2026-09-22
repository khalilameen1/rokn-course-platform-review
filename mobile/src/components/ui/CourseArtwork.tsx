import React, {useEffect, useState} from 'react';
import {type ImageSourcePropType, type ImageStyle, StyleSheet, type StyleProp, View} from 'react-native';
import {RasterImage as Image} from './RasterImage';
import {SvgUri} from 'react-native-svg';

const sourceUri = (source?: ImageSourcePropType) =>
  source && typeof source === 'object' && !Array.isArray(source)
    ? source.uri
    : undefined;

export const isSvgCourseArtwork = (source?: ImageSourcePropType) =>
  /\.svg(?:$|[?#])/i.test(sourceUri(source) || '');

type CourseArtworkProps = {
  fallback: ImageSourcePropType;
  source?: ImageSourcePropType;
  style: StyleProp<ImageStyle>;
};

export const CourseArtwork = ({
  fallback,
  source,
  style,
}: CourseArtworkProps) => {
  const uri = sourceUri(source);
  const [failed, setFailed] = useState(false);

  useEffect(() => setFailed(false), [uri]);

  if (failed || !source) {
    return (
      <Image
        accessibilityElementsHidden
        importantForAccessibility="no"
        source={fallback}
        style={style}
      />
    );
  }

  if (isSvgCourseArtwork(source) && uri) {
    return (
      <View
        accessibilityElementsHidden
        importantForAccessibility="no-hide-descendants"
        style={[style, styles.svgClip]}>
        <SvgUri
          fallback={<Image source={fallback} style={StyleSheet.absoluteFill} />}
          height="100%"
          onError={() => setFailed(true)}
          preserveAspectRatio="xMidYMid slice"
          uri={uri}
          width="100%"
        />
      </View>
    );
  }

  return (
    <Image
      accessibilityElementsHidden
      fadeDuration={120}
      importantForAccessibility="no"
      onError={() => setFailed(true)}
      progressiveRenderingEnabled
      resizeMethod="resize"
      source={source}
      style={style}
    />
  );
};

const styles = StyleSheet.create({
  svgClip: {overflow: 'hidden'},
});
