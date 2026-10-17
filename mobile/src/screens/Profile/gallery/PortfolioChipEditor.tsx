import React from 'react';
import {Pressable, Text, TextInput, View} from 'react-native';

import {Palette} from '../../../constants/designSystem';
import {formatAuthoredDisplayText} from '../../../constants/arabicFormatting';
import {galleryStyles as styles} from './galleryStyles';

/**
 * Tag entry as in Behance's "Tools used" and Dribbble's tags: type, add,
 * tap a chip to remove it. Commas also split entries.
 */
export const PortfolioChipEditor = ({
  addLabel,
  chips,
  disabled,
  input,
  label,
  onAdd,
  onChangeInput,
  onRemove,
  placeholder,
}: {
  addLabel: string;
  chips: string[];
  disabled: boolean;
  input: string;
  label: string;
  onAdd: () => void;
  onChangeInput: (value: string) => void;
  onRemove: (chip: string) => void;
  placeholder: string;
}) => (
  <View>
    <Text style={styles.fieldLabel}>{label}</Text>
    <View style={styles.chipInputRow}>
      <TextInput
        accessibilityLabel={label}
        editable={!disabled}
        maxLength={80}
        onChangeText={onChangeInput}
        onSubmitEditing={onAdd}
        placeholder={placeholder}
        placeholderTextColor={Palette.textFaint}
        returnKeyType="done"
        style={[styles.input, styles.chipInput]}
        value={input}
      />
      <Pressable
        accessibilityLabel={addLabel}
        accessibilityRole="button"
        accessibilityState={{disabled: disabled || !input.trim()}}
        disabled={disabled || !input.trim()}
        onPress={onAdd}
        style={({pressed}) => [
          styles.chipAddButton,
          (disabled || !input.trim()) && styles.detailActionDisabled,
          pressed && styles.detailActionPressed,
        ]}>
        <Text style={styles.detailSecondaryLabel}>ضيف</Text>
      </Pressable>
    </View>
    {!!chips.length && (
      <View style={styles.skillsRow}>
        {chips.map(chip => (
          <Pressable
            accessibilityLabel={`شيل ${chip}`}
            accessibilityRole="button"
            disabled={disabled}
            key={chip}
            onPress={() => onRemove(chip)}
            style={({pressed}) => [
              styles.editableChip,
              pressed && styles.detailActionPressed,
            ]}>
            <Text style={styles.editableChipLabel}>
              {formatAuthoredDisplayText(chip)}  ✕
            </Text>
          </Pressable>
        ))}
      </View>
    )}
  </View>
);
