import React, {useMemo} from 'react';
import {StyleSheet, Text, View} from 'react-native';
import {SettingRow} from '../../components/settings/SettingRow';
import {
  Palette,
  Spacing,
  Type,
  textDirection,
} from '../../constants/designSystem';
import {
  buildSettingsSections,
  type SettingsSectionsProps,
} from './settingsData';

export const SettingsSections = (props: SettingsSectionsProps) => {
  const sections = useMemo(() => buildSettingsSections(props), [props]);
  return (
    <>
      {sections.map(section => (
        <React.Fragment key={section.id}>
          <Text accessibilityRole="header" style={styles.heading}>
            {section.title}
          </Text>
          <View style={styles.group}>
            {section.rows.map(({id, ...row}) => (
              <SettingRow key={id} {...row} />
            ))}
          </View>
        </React.Fragment>
      ))}
    </>
  );
};

const styles = StyleSheet.create({
  heading: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xl,
    marginBottom: Spacing.xs,
  },
  group: {
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Palette.line,
    marginBottom: Spacing.sm,
  },
});
