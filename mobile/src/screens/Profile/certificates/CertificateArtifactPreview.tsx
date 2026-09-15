import React, {useState} from 'react';
import {Image, Text, View} from 'react-native';
import {certificateStyles as styles} from './styles';

type CertificateArtifactPreviewProps = {
  certificateUrl?: string;
  courseTitle: string;
  compact?: boolean;
  pending?: boolean;
};

/** The issued backend artifact is the only full certificate preview. */
export const CertificateArtifactPreview = (
  props: CertificateArtifactPreviewProps,
) => (
  // A different credential owns a fresh image state. Late events from its
  // predecessor cannot change this certificate's size or loading state.
  <CertificateArtifactImage
    key={props.certificateUrl || 'pending'}
    {...props}
  />
);

const CertificateArtifactImage = ({
  certificateUrl,
  courseTitle,
  compact = false,
  pending = false,
}: CertificateArtifactPreviewProps) => {
  const [artifactFailed, setArtifactFailed] = useState(!certificateUrl);
  const [artifactLoaded, setArtifactLoaded] = useState(false);
  const [aspectRatio, setAspectRatio] = useState(
    styles.artifactPreview.aspectRatio,
  );

  return (
    <View
      testID="certificate-artifact-preview"
      style={[styles.artifactPreview, {aspectRatio}]}>
      {!artifactLoaded && (
        <View
          style={[
            styles.artifactState,
            compact && styles.artifactStateCompact,
          ]}>
          <Text
            accessibilityRole="text"
            style={[
              styles.artifactStateText,
              compact && styles.artifactStateTextCompact,
            ]}>
            {artifactFailed
              ? pending
                ? 'نجهّز الشهادة'
                : 'تعذّر تحميل صورة الشهادة'
              : 'جارٍ تحميل الشهادة'}
          </Text>
        </View>
      )}
      {!!certificateUrl && !artifactFailed && (
        <Image
          accessibilityLabel={`شهادة ${courseTitle}`}
          accessibilityRole="image"
          onError={() => setArtifactFailed(true)}
          onLoad={({nativeEvent: {source}}) => {
            const imageAspectRatio = source.width / source.height;
            if (
              Number.isFinite(source.width) &&
              Number.isFinite(source.height) &&
              source.width > 0 &&
              source.height > 0 &&
              Number.isFinite(imageAspectRatio) &&
              imageAspectRatio > 0
            ) {
              setAspectRatio(imageAspectRatio);
            }
            setArtifactLoaded(true);
          }}
          progressiveRenderingEnabled
          resizeMethod="resize"
          resizeMode="contain"
          source={{uri: certificateUrl}}
          style={[
            styles.artifactImage,
            !artifactLoaded && styles.artifactImageLoading,
          ]}
        />
      )}
    </View>
  );
};
