import Clipboard from '@react-native-clipboard/clipboard';
import React, {useEffect, useRef, useState} from 'react';
import {AccessibilityInfo, Alert, Pressable, StyleSheet} from 'react-native';
import {Accessibility, Palette, Radius} from '../../constants/designSystem';
import {CopyIcon} from './CopyIcon';

type Props = {value: string; accessibilityLabel: string; color?: string};

export const CopyButton = ({value, accessibilityLabel, color}: Props) => {
  const [copied, setCopied] = useState(false);
  const resetTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    setCopied(false);
    return () => {
      if (resetTimer.current !== null) clearTimeout(resetTimer.current);
    };
  }, [value]);

  const copy = () => {
    if (!value.trim()) return;
    if (resetTimer.current !== null) clearTimeout(resetTimer.current);

    try {
      Clipboard.setString(value);
    } catch {
      setCopied(false);
      Alert.alert('تعذّر النسخ', 'حاول مرة أخرى');
      return;
    }

    setCopied(true);
    AccessibilityInfo.announceForAccessibility('تم النسخ');
    resetTimer.current = setTimeout(() => setCopied(false), 2000);
  };

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
      accessibilityValue={{text: copied ? 'تم النسخ' : ''}}
      accessibilityState={{disabled: !value.trim()}}
      disabled={!value.trim()}
      onPress={copy}
      style={({pressed}) => [styles.button, pressed && styles.pressed]}>
      <CopyIcon copied={copied} color={color} />
    </Pressable>
  );
};

const styles = StyleSheet.create({
  button: {
    minWidth: Accessibility.minTouchTarget,
    minHeight: Accessibility.minTouchTarget,
    flexShrink: 0,
    alignSelf: 'flex-start',
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.sm,
  },
  pressed: {backgroundColor: Palette.surfacePressed},
});
