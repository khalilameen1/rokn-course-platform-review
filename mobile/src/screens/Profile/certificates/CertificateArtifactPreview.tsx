import React, {useState} from 'react';
import {Image, Text, View} from 'react-native';
import {certificateStyles as styles} from './styles';

/** The issued backend artifact is the only full certificate preview. */
export const CertificateArtifactPreview = ({
  certificateUrl,
  courseTitle,
  pending = false,
}: {
  certificateUrl?: string;
  courseTitle: string;
  pending?: boolean;
}) => {
  const [artifactFailed, setArtifactFailed] = useState(false);
  const [artifactLoaded, setArtifactLoaded] = useState(false);

  React.useEffect(() => {
    setArtifactFailed(!certificateUrl);
    setArtifactLoaded(false);
  }, [certificateUrl]);

  return (
    <View style={styles.artifactPreview}>
      {!artifactLoaded && (
        <View style={styles.artifactState}>
          <Text accessibilityRole="text" style={styles.artifactStateText}>
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
          onLoad={() => setArtifactLoaded(true)}
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
