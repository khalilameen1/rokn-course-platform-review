import React from 'react';
import {StyleSheet, Text, View} from 'react-native';
import {formatAuthoredDisplayText} from '../../constants/arabicFormatting';
import {
  Palette,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import {CopyButton} from '../ui/CopyButton';

type Props = {id: string; name: string};

export const SettingsAccountIdentity = ({id, name}: Props) => {
  return (
    <View style={styles.container}>
      <Text style={styles.name}>{formatAuthoredDisplayText(name)}</Text>
      <View style={styles.identityRow}>
        <Text selectable style={styles.identity}>{`UID: ${id}`}</Text>
        <CopyButton value={id} accessibilityLabel="نسخ UID" />
      </View>
    </View>
  );
};

const styles = StyleSheet.create({
  container: {paddingTop: Spacing.sm, paddingBottom: Spacing.sm},
  name: {...Type.title, ...textDirection, color: Palette.text},
  identityRow: {
    ...rtlRowStyle,
    flexWrap: 'wrap',
    alignItems: 'center',
    gap: Spacing.xs,
    marginTop: Spacing.xxs,
  },
  identity: {
    ...Type.caption,
    direction: 'ltr',
    writingDirection: 'ltr',
    textAlign: 'left',
    color: Palette.textMuted,
    flexShrink: 1,
  },
});
