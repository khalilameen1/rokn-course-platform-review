import React from 'react';
import {
  Image,
  ImageStyle,
  StyleProp,
  StyleSheet,
  Text,
  TextStyle,
  View,
  ViewStyle,
} from 'react-native';
import {Fonts} from '../../constants/styleConstants';
import {formatArabicNumber} from '../../constants/arabicFormatting';
import {Palette, rtlRowStyle} from '../../constants/designSystem';

type Props = {
  size?: number;
  style?: StyleProp<ViewStyle>;
};

const RoknCoin = React.memo(({size = 30, style}: Props) => (
  <View
    accessibilityElementsHidden
    importantForAccessibility="no-hide-descendants"
    style={[{width: size, height: size}, style]}>
    <Image
      source={require('../../assets/images/coins/rokn-coin-minted.png')}
      style={{width: size, height: size}}
      resizeMode="contain"
      resizeMethod="resize"
      fadeDuration={0}
    />
  </View>
));

RoknCoin.displayName = 'RoknCoin';

const styles = StyleSheet.create({
  amount: {
    ...rtlRowStyle,
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 6,
  },
  amountText: {
    flexShrink: 1,
    minWidth: 0,
    color: Palette.coin,
    fontFamily: Fonts.semiBold,
    fontSize: 13,
  },
});

export default RoknCoin;

export const RoknCoinStack = ({
  size = 128,
  style,
}: {
  size?: number;
  style?: StyleProp<ImageStyle>;
}) => (
  <Image
    accessibilityElementsHidden
    importantForAccessibility="no-hide-descendants"
    source={require('../../assets/images/coins/rokn-coin-stack-3d-alpha.png')}
    style={[{width: size, height: size, resizeMode: 'contain'}, style]}
  />
);

export const CoinAmount = ({
  value,
  size = 18,
  style,
  textStyle,
}: {
  value: number;
  size?: number;
  style?: StyleProp<ViewStyle>;
  textStyle?: StyleProp<TextStyle>;
}) => (
  <View
    accessibilityLabel={`${formatArabicNumber(value)} من رصيد ركن`}
    style={[styles.amount, style]}>
    <Text
      maxFontSizeMultiplier={2}
      numberOfLines={1}
      style={[styles.amountText, textStyle]}>
      {formatArabicNumber(value)}
    </Text>
    <RoknCoin size={size} />
  </View>
);
