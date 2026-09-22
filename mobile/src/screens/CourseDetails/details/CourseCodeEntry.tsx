import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  Text,
  TextInput,
  View,
} from 'react-native';
import {Palette} from '../../../constants/designSystem';
import styles from './styles';

type Props = {
  busy: boolean;
  code: string;
  onChange: (value: string) => void;
  onRedeem: () => void | Promise<void>;
};

export function CourseCodeEntry({busy, code, onChange, onRedeem}: Props) {
  const disabled = busy || !code.trim();
  return (
    <View style={styles.codeRow}>
      <TextInput
        accessibilityHint="أدخل الكود الذي استلمته من الجهة المانحة"
        accessibilityLabel="كود منحة"
        autoCapitalize="characters"
        autoCorrect={false}
        editable={!busy}
        maxLength={50}
        onChangeText={onChange}
        onSubmitEditing={() => {
          if (!disabled) void onRedeem();
        }}
        placeholder="اكتب كود المنحة"
        placeholderTextColor={Palette.textFaint}
        returnKeyType="done"
        style={styles.codeInput}
        value={code}
      />
      <Pressable
        accessibilityLabel="تفعيل كود المنحة"
        accessibilityRole="button"
        accessibilityState={{busy, disabled}}
        disabled={disabled}
        onPress={() => void onRedeem()}
        style={({pressed}) => [
          styles.codeButton,
          pressed && styles.pressed,
          disabled && styles.disabled,
        ]}>
        {busy ? (
          <ActivityIndicator color={Palette.text} size="small" />
        ) : (
          <Text style={styles.codeButtonText}>تفعيل</Text>
        )}
      </Pressable>
    </View>
  );
}
