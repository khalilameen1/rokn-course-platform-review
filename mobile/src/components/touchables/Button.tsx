import React, {FC} from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleProp,
  StyleSheet,
  Text,
  TextStyle,
  View,
  ViewStyle,
} from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import {Colors, PixelPerfect} from '../../constants/styleConstants';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
} from '../../constants/designSystem';

interface GradientConfig {
  colors: string[];
  locations?: number[];
  start?: {x: number; y: number};
  end?: {x: number; y: number};
}

interface Props {
  title: string;
  loaderColor?: string;
  style?: StyleProp<ViewStyle>;
  styleTitle?: StyleProp<TextStyle>;
  loader?: boolean;
  disable?: boolean;
  onPress?: () => void;
  icon?: React.ReactNode;
  useGradient?: boolean;
  gradientConfig?: GradientConfig;
  accessibilityLabel?: string;
}

const Button: FC<Props> = ({
  onPress,
  disable,
  title,
  style,
  styleTitle,
  loader,
  loaderColor,
  icon,
  useGradient = true,
  gradientConfig,
  accessibilityLabel,
}) => {
  // Default gradient configuration
  const defaultGradient: GradientConfig = {
    colors: [Palette.action, Palette.actionPressed],
    start: {x: 0, y: 0},
    end: {x: 0, y: 1},
  };

  // Merge custom gradient with defaults
  const gradient = gradientConfig
    ? {
        ...defaultGradient,
        ...gradientConfig,
      }
    : defaultGradient;

  const containerStyle: StyleProp<ViewStyle> = [
    styles.container,
    !useGradient && styles.secondary,
    disable && styles.disabled,
    icon ? {gap: PixelPerfect(8)} : undefined,
    style,
  ].filter(Boolean) as StyleProp<ViewStyle>;

  const content = loader ? (
    <View style={styles.loaderContainer}>
      <ActivityIndicator
        color={loaderColor ?? Colors.secondColor}
        size={PixelPerfect(30)}
      />
    </View>
  ) : (
    <>
      {icon && <View style={styles.iconSlot}>{icon}</View>}
      <Text
        style={
          [
            styles.title,
            icon ? {width: 'auto'} : undefined,
            {color: disable ? Palette.textFaint : Colors.white},
            styleTitle,
          ].filter(Boolean) as StyleProp<TextStyle>
        }>
        {title}
      </Text>
    </>
  );

  return (
    <Pressable
      accessibilityLabel={accessibilityLabel ?? title}
      accessibilityRole="button"
      accessibilityState={{disabled: !!disable, busy: !!loader}}
      disabled={disable || loader}
      onPress={onPress}
      style={({pressed}) => pressed && styles.pressed}>
      {useGradient && gradientConfig && !disable ? (
        <LinearGradient
          colors={gradient.colors}
          locations={gradient.locations}
          start={gradient.start}
          end={gradient.end}
          style={containerStyle}>
          {content}
        </LinearGradient>
      ) : (
        <View style={containerStyle}>{content}</View>
      )}
    </Pressable>
  );
};

export default Button;

const styles = StyleSheet.create({
  container: {
    ...rtlRowStyle,
    justifyContent: 'center',
    alignItems: 'center',
    minHeight: Math.max(Accessibility.minTouchTarget, PixelPerfect(52)),
    borderRadius: Radius.md,
    paddingHorizontal: Spacing.lg,
    backgroundColor: Palette.action,
    marginVertical: Spacing.md,
  },
  secondary: {
    backgroundColor: Palette.surfaceRaised,
  },
  disabled: {
    backgroundColor: Palette.surfacePressed,
    opacity: 0.72,
  },
  title: {
    ...Type.button,
    color: Palette.text,
    textAlign: 'center',
    flexShrink: 1,
  },
  iconSlot: {
    width: PixelPerfect(30),
    minWidth: PixelPerfect(30),
    height: PixelPerfect(30),
    flexShrink: 0,
    alignItems: 'center',
    justifyContent: 'center',
  },
  loaderContainer: {
    width: '100%',
    justifyContent: 'center',
    alignItems: 'center',
  },
  pressed: {opacity: 0.82, transform: [{scale: 0.99}]},
});
