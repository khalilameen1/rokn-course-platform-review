import Clipboard from '@react-native-clipboard/clipboard';
import React, {useState} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import {formatAuthoredDisplayText} from '../../constants/arabicFormatting';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';

type Props = {id: string; name: string};

export const SettingsAccountIdentity = ({id, name}: Props) => {
  const [copyResult, setCopyResult] = useState<'copied' | 'failed' | null>(
    null,
  );
  const copyIdentity = () => {
    try {
      Clipboard.setString(id);
      setCopyResult('copied');
    } catch {
      setCopyResult('failed');
    }
  };

  return (
    <View style={styles.container}>
      <Text style={styles.name}>{formatAuthoredDisplayText(name)}</Text>
      <View style={styles.identityRow}>
        <Text selectable style={styles.identity}>{`UID: ${id}`}</Text>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="نسخ UID"
          onPress={copyIdentity}
          style={({pressed}) => [styles.copyButton, pressed && styles.pressed]}>
          <Text style={styles.copyLabel}>نسخ</Text>
        </Pressable>
      </View>
      {copyResult && (
        <Text
          accessibilityLiveRegion="polite"
          style={[styles.feedback, copyResult === 'failed' && styles.failure]}>
          {copyResult === 'copied' ? 'تم النسخ' : 'تعذّر النسخ\nحاول مرة أخرى'}
        </Text>
      )}
    </View>
  );
};

const styles = StyleSheet.create({
  container: {paddingHorizontal: Spacing.md, paddingBottom: Spacing.xl},
  name: {...Type.section, ...textDirection, color: Palette.text},
  identityRow: {
    ...rtlRowStyle,
    flexWrap: 'wrap',
    alignItems: 'center',
    gap: Spacing.xs,
    marginTop: Spacing.xxs,
  },
  identity: {
    ...Type.body,
    direction: 'ltr',
    writingDirection: 'ltr',
    textAlign: 'left',
    color: Palette.textMuted,
    flexShrink: 1,
  },
  copyButton: {
    minWidth: Accessibility.minTouchTarget,
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: Spacing.sm,
    borderRadius: Radius.sm,
    backgroundColor: Palette.surfaceRaised,
  },
  copyLabel: {...Type.caption, ...textDirection, color: Palette.text},
  feedback: {...Type.caption, ...textDirection, color: Palette.success},
  failure: {color: Palette.danger},
  pressed: {opacity: 0.75},
});
