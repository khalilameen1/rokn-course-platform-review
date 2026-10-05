import React from 'react';
import {StyleSheet, Text, View} from 'react-native';
import {RasterImage} from './RasterImage';
import {Fonts} from '../../constants/styleConstants';

/** Approved Cake-style composition: full wordmark and one Arabic slogan. */
export const StartupBrand = () => (
  <View testID="startup-brand" style={styles.screen}>
    <View
      accessible
      accessibilityLabel="رُكن كورسات هتكملها"
      style={styles.brand}>
      <RasterImage
        accessibilityElementsHidden
        importantForAccessibility="no"
        source={require('../../assets/images/logo.png')}
        style={styles.wordmark}
        resizeMode="contain"
        fadeDuration={0}
      />
      <Text style={styles.slogan} maxFontSizeMultiplier={1.5}>
        كورسات <Text style={styles.emphasis}>هتكملها</Text>
      </Text>
    </View>
  </View>
);

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: '#0B1628',
    alignItems: 'center',
    justifyContent: 'center',
  },
  brand: {alignItems: 'center', paddingHorizontal: 24, paddingVertical: 32},
  wordmark: {width: 205, height: 68},
  slogan: {
    fontFamily: Fonts.medium,
    fontSize: 18,
    lineHeight: 32,
    color: '#B8C3D4',
    writingDirection: 'rtl',
    textAlign: 'center',
    marginTop: 23,
  },
  emphasis: {fontFamily: Fonts.extraBold, color: '#FFFFFF'},
});
