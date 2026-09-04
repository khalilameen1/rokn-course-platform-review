import React from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
  useResponsiveLayout,
} from '../../constants/designSystem';
import {CoinAmount} from '../ui/RoknCoin';
import {
  formatArabicDisplayText,
  formatArabicNumber,
  toArabicDigits,
} from '../../constants/arabicFormatting';

interface PackageProps {
  title?: string;
  price: string;
  rPrice: string;
  buttonTitle?: string;
  onPress?: () => void;
  disabled?: boolean;
  width?: number;
  displayPrice?: string;
}

const Package = React.memo<PackageProps>(
  ({
    title,
    price,
    rPrice,
    buttonTitle = 'اشحن الآن',
    onPress,
    disabled = false,
    width,
    displayPrice,
  }) => {
    const {contentWidth, gutter, isTablet} = useResponsiveLayout();
    const fallbackWidth = isTablet
      ? Math.min(260, contentWidth - gutter * 2)
      : Math.max(0, contentWidth - gutter * 2);
    const numericPrice = Number(price);
    const numericCoins = Number(rPrice);
    const visiblePrice = displayPrice || (Number.isFinite(numericPrice)
      ? formatArabicNumber(numericPrice, {maximumFractionDigits: 2})
      : toArabicDigits(price));
    const visibleCoins = Number.isFinite(numericCoins)
      ? formatArabicNumber(numericCoins)
      : toArabicDigits(rPrice);
    return (
      <Pressable
        accessibilityLabel={`${title ? `${formatArabicDisplayText(title)}، ` : ''}${visibleCoins} من رصيد ركن مقابل ${visiblePrice}${displayPrice ? '' : ' جنيه'}`}
        accessibilityRole="button"
        accessibilityState={{disabled}}
        disabled={disabled}
        onPress={onPress}
        style={({pressed}) => [
          styles.card,
          {width: width ?? fallbackWidth},
          disabled && styles.disabled,
          pressed && styles.pressed,
        ]}>
        {!!title && (
          <Text style={styles.title}>
            {formatArabicDisplayText(title)}
          </Text>
        )}
        <CoinAmount
          size={21}
          style={styles.coins}
          textStyle={styles.coinsText}
          value={numericCoins}
        />
        <Text style={styles.price}>
          {visiblePrice}{displayPrice ? '' : ' جنيه'}
        </Text>
        <View style={styles.action}>
          <Text style={styles.actionLabel}>
            {formatArabicDisplayText(buttonTitle)}
          </Text>
        </View>
      </Pressable>
    );
  },
);

Package.displayName = 'Package';
const styles = StyleSheet.create({
  card: {
    padding: Spacing.md,
    minWidth: 0,
    borderRadius: Radius.lg,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: Palette.surface,
  },
  title: {...Type.caption, ...textDirection, color: Palette.textMuted, flexShrink: 1},
  coins: {marginTop: Spacing.xxs},
  coinsText: {...Type.title, color: Palette.text},
  price: {...Type.caption, ...textDirection, color: '#E9C66F', marginTop: Spacing.xxs},
  action: {
    minHeight: 42,
    borderRadius: Radius.md,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.action,
    marginTop: Spacing.md,
  },
  actionLabel: {...Type.bodyStrong, color: Palette.text},
  pressed: {opacity: 0.82, transform: [{scale: 0.985}]},
  disabled: {opacity: 0.52},
});
export default Package;
