import React, {useRef, useState} from 'react';
import {Alert, Pressable, StyleSheet, Text} from 'react-native';
import {reportAiResponse, type AiReportTarget} from '../../services/aiReporting';
import {Palette, Type} from '../../constants/designSystem';

export const AiResponseReportButton = ({target}: {target: AiReportTarget}) => {
  const [sending, setSending] = useState(false);
  const flight = useRef(false);
  const report = async () => {
    if (flight.current) return;
    flight.current = true;
    setSending(true);
    try {
      await reportAiResponse(target);
      Alert.alert('وصل بلاغك', 'سيراجع فريق ركن الرد\nيمكنك متابعة البلاغ من الإعدادات ← تواصل معنا');
    } catch {
      Alert.alert('لم يصل البلاغ', 'حاول مرة أخرى');
    } finally {
      flight.current = false;
      setSending(false);
    }
  };
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel="الإبلاغ عن رد الذكاء الاصطناعي"
      accessibilityState={{disabled: sending, busy: sending}}
      disabled={sending}
      style={styles.button}
      onPress={() => Alert.alert('الإبلاغ عن الرد', 'سنرسل هذا الرد إلى فريق ركن لمراجعته', [
        {text: 'إلغاء', style: 'cancel'},
        {text: 'إرسال البلاغ', onPress: () => void report()},
      ])}>
      <Text style={styles.label}>{sending ? 'جارٍ الإبلاغ' : 'الإبلاغ عن الرد'}</Text>
    </Pressable>
  );
};

const styles = StyleSheet.create({
  button: {minHeight: 48, minWidth: 48, justifyContent: 'center', alignSelf: 'flex-start', paddingHorizontal: 8},
  label: {...Type.caption, color: Palette.textMuted},
});
