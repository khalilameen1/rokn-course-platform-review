import React from 'react';
import {
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import {useReducedMotion} from '../../hooks/useReducedMotion';
import {useSafeAreaInsets} from 'react-native-safe-area-context';

export type SettingsChoice = 'quality' | 'reminderTime' | null;

type Props = {
  bottomInset: number;
  choice: SettingsChoice;
  onClose: () => void;
  onSelect: (key: string) => void;
  quality: string;
  reminderHour: number;
};

const choicesFor = (choice: SettingsChoice) => {
  if (choice === 'reminderTime') {
    return [
      {key: '10', label: 'صباحًا · ١٠:٠٠'},
      {key: '15', label: 'بعد الظهر · ٣:٠٠'},
      {key: '20', label: 'مساءً · ٨:٠٠'},
    ];
  }
  return [
    {key: 'auto', label: 'تلقائي — موصى به'},
    {key: 'data_saver', label: 'توفير البيانات'},
    {key: '720p', label: '٧٢٠ بكسل'},
    {key: '1080p', label: '١٠٨٠ بكسل'},
  ];
};

export const SettingsChoiceModal = ({
  bottomInset,
  choice,
  onClose,
  onSelect,
  quality,
  reminderHour,
}: Props) => {
  const reducedMotion = useReducedMotion();
  const insets = useSafeAreaInsets();
  const selectedKey =
    choice === 'reminderTime' ? String(reminderHour) : quality;
  const title =
    choice === 'reminderTime' ? 'وقت مناسب لتذكيرك' : 'جودة الفيديو الافتراضية';

  return (
    <Modal
      animationType={reducedMotion ? 'none' : 'fade'}
      onRequestClose={onClose}
      statusBarTranslucent
      transparent
      visible={Boolean(choice)}>
      <View style={styles.overlay}>
        <Pressable
          accessibilityLabel="إغلاق النافذة"
          accessibilityRole="button"
          onPress={onClose}
          style={StyleSheet.absoluteFill}
        />
        <View
          accessibilityViewIsModal
          style={[
            styles.sheet,
            {
              paddingBottom: Math.max(Spacing.xl, bottomInset + Spacing.md),
              paddingLeft: Math.max(Spacing.xl, insets.left + Spacing.md),
              paddingRight: Math.max(Spacing.xl, insets.right + Spacing.md),
            },
          ]}>
          <ScrollView
            bounces={false}
            contentContainerStyle={styles.content}
            showsVerticalScrollIndicator={false}>
            <Text accessibilityRole="header" style={styles.title}>
              {title}
            </Text>
            <View accessibilityRole="radiogroup">
              {choicesFor(choice).map(option => {
                const selected = option.key === selectedKey;
                return (
                  <Pressable
                    accessibilityLabel={option.label}
                    accessibilityRole="radio"
                    accessibilityState={{checked: selected}}
                    key={option.key}
                    onPress={() => onSelect(option.key)}
                    style={[styles.row, selected && styles.rowSelected]}>
                    <Text
                      style={[styles.label, selected && styles.labelSelected]}>
                      {option.label}
                    </Text>
                    <View
                      style={[styles.radio, selected && styles.radioSelected]}>
                      {selected && <View style={styles.radioDot} />}
                    </View>
                  </Pressable>
                );
              })}
            </View>
            <Pressable
              accessibilityRole="button"
              onPress={onClose}
              style={styles.closeButton}>
              <Text style={styles.closeLabel}>إغلاق</Text>
            </Pressable>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
};

const styles = StyleSheet.create({
  overlay: {
    flex: 1,
    backgroundColor: Palette.overlay,
    justifyContent: 'flex-end',
    alignItems: 'center',
  },
  sheet: {
    width: '100%',
    maxWidth: 620,
    maxHeight: '90%',
    backgroundColor: Palette.canvasSoft,
    borderTopLeftRadius: Radius.xl,
    borderTopRightRadius: Radius.xl,
    padding: Spacing.xl,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    overflow: 'hidden',
  },
  content: {paddingBottom: Spacing.xs},
  title: {
    ...Type.title,
    ...textDirection,
    color: Palette.text,
    marginBottom: Spacing.md,
  },
  row: {
    minHeight: 64,
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: Spacing.sm,
    paddingVertical: Spacing.xs,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: Palette.lineSoft,
    borderRadius: Radius.sm,
  },
  rowSelected: {backgroundColor: Palette.primarySoft},
  label: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
    flex: 1,
    minWidth: 0,
  },
  labelSelected: {color: '#A9C9FF'},
  radio: {
    width: 22,
    height: 22,
    borderRadius: 11,
    borderWidth: 1.5,
    borderColor: Palette.textFaint,
    alignItems: 'center',
    justifyContent: 'center',
    flexShrink: 0,
    marginStart: Spacing.sm,
  },
  radioSelected: {borderColor: Palette.primary},
  radioDot: {
    width: 10,
    height: 10,
    borderRadius: 5,
    backgroundColor: Palette.primary,
  },
  closeButton: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: Spacing.md,
  },
  closeLabel: {...Type.bodyStrong, ...textDirection, color: Palette.textMuted},
});
