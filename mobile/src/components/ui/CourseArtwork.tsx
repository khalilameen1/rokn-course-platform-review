import React, {useState} from 'react';
import {
  type ImageSourcePropType,
  type ImageStyle,
  StyleSheet,
  type StyleProp,
  View,
} from 'react-native';
import {RasterImage as Image} from './RasterImage';
import {Image as CachedImage} from 'expo-image';
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

// A source change gives its error state a fresh owner. Late callbacks from the
// former image cannot replace a recycled card's newly loaded cover.
export const CourseArtwork = (props: CourseArtworkProps) => (
  <CourseArtworkFrame
    key={sourceUri(props.source) ?? JSON.stringify(props.source)}
    {...props}
  />
);

const CourseArtworkFrame = ({fallback, source, style}: CourseArtworkProps) => {
  const uri = sourceUri(source);
  const [failed, setFailed] = useState(false);

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
    <CachedImage
      accessibilityElementsHidden
      accessible={false}
      allowDownscaling
      cachePolicy="disk"
      contentFit="cover"
      importantForAccessibility="no"
      onError={() => setFailed(true)}
      recyclingKey={uri}
      source={source}
      style={style}
      transition={120}
      useAppleWebpCodec
    />
  );
};

const styles = StyleSheet.create({
  svgClip: {overflow: 'hidden'},
});
