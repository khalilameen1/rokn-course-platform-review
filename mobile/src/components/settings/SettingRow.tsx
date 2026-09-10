import React from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import type {SvgProps} from 'react-native-svg';
import {MoreSectionArrowLeft} from '../../assets/SVG';
import {
  fixedIconSlot,
  flexibleTextColumn,
  Palette,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
  useResponsiveLayout,
} from '../../constants/designSystem';
import {toArabicDigits} from '../../constants/arabicFormatting';
import {buildSettingRowAccessibility} from '../settingRowAccessibility';

type Props = {
  title: string;
  subtitle?: string;
  value?: string;
  onPress?: () => void;
  destructive?: boolean;
  toggle?: {value: boolean; onChange: (value: boolean) => void};
  isLast?: boolean;
  icon?: React.ComponentType<SvgProps>;
};

export const SettingRow = ({
  title,
  subtitle,
  value,
  onPress,
  destructive,
  toggle,
  isLast,
  icon: Icon,
}: Props) => {
  const {fontScale, width} = useResponsiveLayout();
  const visibleValue = value ? toArabicDigits(value) : '';
  const stackValue = Boolean(value) && (width < 430 || fontScale > 1.18);

  return (
    <Pressable
      {...buildSettingRowAccessibility({
        title,
        subtitle,
        value: visibleValue,
        hasAction: Boolean(onPress),
        toggleValue: toggle?.value,
      })}
      onPress={toggle ? () => toggle.onChange(!toggle.value) : onPress}
      style={({pressed}) => [
        styles.row,
        !isLast && styles.rowBorder,
        pressed && styles.pressed,
      ]}>
      <View style={styles.rowLead}>
        {!!Icon && (
          <View
            accessibilityElementsHidden
            importantForAccessibility="no-hide-descendants"
            style={[styles.rowIcon, destructive && styles.rowIconDanger]}>
            <Icon width={22} height={22} />
          </View>
        )}
        <View style={styles.rowCopy}>
          <Text style={[styles.rowTitle, destructive && styles.destructive]}>
            {title}
          </Text>
          {!!subtitle && <Text style={styles.rowSubtitle}>{subtitle}</Text>}
          {stackValue && (
            <Text style={styles.rowValueStacked}>{visibleValue}</Text>
          )}
        </View>
      </View>
      {((!!visibleValue && !stackValue) || !!toggle || !!onPress) && (
        <View
          accessibilityElementsHidden
          importantForAccessibility="no-hide-descendants"
          style={styles.rowTrailing}>
          {!!visibleValue && !stackValue && (
            <Text style={styles.rowValue}>{visibleValue}</Text>
          )}
          {toggle ? (
            <View
              style={[
                styles.switchTrack,
                toggle.value && styles.switchTrackOn,
              ]}>
              <View
                style={[
                  styles.switchThumb,
                  toggle.value && styles.switchThumbOn,
                ]}
              />
            </View>
          ) : onPress ? (
            <MoreSectionArrowLeft width={18} height={18} />
          ) : null}
        </View>
      )}
    </Pressable>
  );
};

const styles = StyleSheet.create({
  row: {
    minHeight: 72,
    ...rtlRowStyle,
    alignItems: 'center',
    columnGap: Spacing.sm,
    overflow: 'hidden',
    paddingHorizontal: 0,
    paddingVertical: Spacing.md,
  },
  rowBorder: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: Palette.lineSoft,
  },
  rowLead: {
    flexGrow: 1,
    flexShrink: 1,
    flexBasis: 0,
    minWidth: 0,
    overflow: 'hidden',
    ...rtlRowStyle,
    alignItems: 'center',
    columnGap: Spacing.sm,
  },
  rowCopy: {
    ...flexibleTextColumn,
    flex: 1,
    direction: 'rtl',
    alignItems: 'stretch',
  },
  rowTrailing: {
    minWidth: 52,
    maxWidth: '36%',
    flexShrink: 1,
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'center',
    columnGap: Spacing.xxs,
  },
  rowIcon: {
    ...fixedIconSlot,
    width: 32,
    minWidth: 32,
    height: 40,
  },
  rowIconDanger: {opacity: 0.9},
  rowTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
    width: '100%',
    flexShrink: 1,
  },
  rowSubtitle: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    width: '100%',
    marginTop: 2,
    flexShrink: 1,
  },
  rowValue: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    maxWidth: 132,
    flexShrink: 1,
  },
  rowValueStacked: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    width: '100%',
    marginTop: 2,
    flexShrink: 1,
  },
  destructive: {color: Palette.danger},
  switchTrack: {
    position: 'relative',
    width: 48,
    height: 28,
    flexShrink: 0,
    borderRadius: 14,
    backgroundColor: Palette.surfacePressed,
  },
  switchTrackOn: {backgroundColor: Palette.primary},
  switchThumb: {
    position: 'absolute',
    top: 3,
    end: 3,
    width: 22,
    height: 22,
    borderRadius: 11,
    backgroundColor: Palette.textMuted,
  },
  switchThumbOn: {end: 23, backgroundColor: Palette.text},
  pressed: {opacity: 0.72},
});
