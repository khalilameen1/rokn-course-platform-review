import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import {formatAuthoredDisplayText} from '../../constants/arabicFormatting';

type Props = {
  recent: string[];
  suggestions: string[];
  visible: boolean;
  searching?: boolean;
  onClearRecent: () => void;
  onSelect: (value: string) => void;
};

const Chip = ({label, onPress}: {label: string; onPress: () => void}) => (
  <Pressable
    accessibilityLabel={`ابحث عن ${label}`}
    accessibilityRole="button"
    onPress={onPress}
    style={({pressed}) => [styles.chip, pressed && styles.pressed]}>
    <Text numberOfLines={1} style={styles.chipText}>
      {formatAuthoredDisplayText(label)}
    </Text>
  </Pressable>
);

export default function SearchAssist({
  recent,
  suggestions,
  visible,
  searching,
  onClearRecent,
  onSelect,
}: Props) {
  if (searching) {
    return (
      <View accessibilityLiveRegion="polite" style={styles.searchingRow}>
        <ActivityIndicator color={Palette.primary} size="small" />
        <Text style={styles.searchingText}>
          نعرض النتائج المتاحة ونبحث في بقية الكورسات
        </Text>
      </View>
    );
  }

  if (!visible) return null;

  return (
    <View style={styles.panel}>
      {!!recent.length && (
        <>
          <View style={styles.headingRow}>
            <Text style={styles.heading}>عمليات البحث السابقة</Text>
            <Pressable
              accessibilityLabel="مسح سجل البحث"
              accessibilityRole="button"
              hitSlop={8}
              onPress={onClearRecent}
              style={({pressed}) => [
                styles.clearButton,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.clearText}>مسح</Text>
            </Pressable>
          </View>
          <View style={styles.chips}>
            {recent.map(item => (
              <Chip
                key={`recent-${item}`}
                label={item}
                onPress={() => onSelect(item)}
              />
            ))}
          </View>
        </>
      )}
      <Text
        style={[
          styles.heading,
          recent.length > 0 && styles.suggestionsHeading,
        ]}>
        ابحث عن
      </Text>
      <View style={styles.chips}>
        {suggestions.map(item => (
          <Chip
            key={`suggestion-${item}`}
            label={item}
            onPress={() => onSelect(item)}
          />
        ))}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  panel: {
    marginTop: -Spacing.sm,
    marginBottom: Spacing.lg,
    padding: Spacing.md,
    borderRadius: Radius.lg,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: Palette.surface,
  },
  headingRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    minHeight: Accessibility.minTouchTarget,
  },
  heading: {...Type.caption, ...textDirection, color: Palette.textMuted},
  suggestionsHeading: {marginTop: Spacing.md, marginBottom: Spacing.sm},
  clearButton: {
    minWidth: Accessibility.minTouchTarget,
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    marginEnd: -Spacing.sm,
  },
  clearText: {...Type.caption, color: Palette.primary},
  chips: {...rtlRowStyle, flexWrap: 'wrap', gap: Spacing.xs},
  chip: {
    maxWidth: '100%',
    minHeight: Accessibility.minTouchTarget,
    justifyContent: 'center',
    paddingHorizontal: Spacing.md,
    borderRadius: Radius.pill,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: Palette.surfaceRaised,
  },
  chipText: {...Type.caption, ...textDirection, color: Palette.text},
  searchingRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: Spacing.sm,
    minHeight: Accessibility.minTouchTarget,
    marginTop: -Spacing.sm,
    marginBottom: Spacing.md,
    paddingHorizontal: Spacing.md,
  },
  searchingText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    flex: 1,
  },
  pressed: {opacity: 0.68},
});
