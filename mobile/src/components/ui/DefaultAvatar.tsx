import React from 'react';
import {StyleSheet, View} from 'react-native';
import Svg, {Circle, Path} from 'react-native-svg';
import {Palette} from '../../constants/designSystem';

type DefaultAvatarProps = {
  accessibilityLabel: string;
  size: number;
};

/** A deliberately flat fallback that stays inside the app's icon language. */
export const DefaultAvatar = ({
  accessibilityLabel,
  size,
}: DefaultAvatarProps) => (
  <View
    accessibilityLabel={accessibilityLabel}
    accessible
    style={[styles.shell, {height: size, width: size}]}>
    <Svg height={size} viewBox="0 0 72 72" width={size}>
      <Circle
        cx={36}
        cy={36}
        fill={Palette.surfacePressed}
        r={35}
        stroke={Palette.line}
        strokeWidth={2}
      />
      <Circle cx={36} cy={27} fill={Palette.textMuted} r={11} />
      <Path
        d="M14 65c1.8-14 10.2-21 22-21s20.2 7 22 21H14Z"
        fill={Palette.textMuted}
      />
    </Svg>
  </View>
);

const styles = StyleSheet.create({
  shell: {
    alignItems: 'center',
    borderRadius: 999,
    justifyContent: 'center',
    overflow: 'hidden',
  },
});
