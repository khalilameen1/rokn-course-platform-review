import React, {useEffect, useLayoutEffect, useSyncExternalStore} from 'react';
import {
  ActivityIndicator,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import {useReducedMotion} from '../../hooks/useReducedMotion';
import {attachmentDownloadNoticeHost as host} from './attachmentDownloadNotice';

export const AttachmentDownloadNoticeHost = () => {
  const state = useSyncExternalStore(
    host.subscribe,
    host.getSnapshot,
    host.getSnapshot,
  );
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  const id = state?.id;
  const visible = state?.visible ?? false;
  useLayoutEffect(() => {
    if (Platform.OS === 'ios' && id !== undefined && visible)
      host.requested(id);
  }, [id, visible]);
  useEffect(
    () => () => {
      if (Platform.OS === 'ios') host.retire();
    },
    [],
  );
  if (Platform.OS !== 'ios' || !state) return null;
  return (
    <Modal
      key={state.id}
      transparent
      visible={state.visible}
      animationType={reducedMotion ? 'none' : 'fade'}
      onShow={() => host.shown(state.id)}
      onDismiss={() => host.dismissed(state.id)}
      onRequestClose={() => host.hide(state.id)}>
      <View
        style={[
          styles.overlay,
          {
            paddingTop: insets.top + Spacing.lg,
            paddingBottom: insets.bottom + Spacing.lg,
          },
        ]}>
        <View accessibilityViewIsModal style={styles.card}>
          <Text accessibilityRole="header" style={styles.heading}>
            جارٍ تنزيل الملف
          </Text>
          <ScrollView contentContainerStyle={styles.items}>
            {state.notices.map(notice => (
              <View key={notice.id} style={styles.row}>
                <ActivityIndicator color={Palette.primary} />
                <View style={styles.copy}>
                  <Text style={styles.title}>{notice.title}</Text>
                  {notice.size ? (
                    <Text style={styles.secondary}>{notice.size}</Text>
                  ) : null}
                </View>
                {notice.transferPending &&
                  !notice.cancelled &&
                  notice.isCurrent() && (
                    <Pressable
                      accessibilityRole="button"
                      accessibilityLabel={`إلغاء تنزيل ${notice.title}`}
                      onPress={() => host.cancel(state.id, notice.id)}
                      style={styles.button}>
                      <Text style={styles.cancel}>إلغاء</Text>
                    </Pressable>
                  )}
              </View>
            ))}
          </ScrollView>
          <Text style={styles.secondary}>سنفتح خيارات الحفظ عند اكتماله</Text>
          <Pressable
            accessibilityRole="button"
            onPress={() => host.hide(state.id)}
            style={styles.button}>
            <Text style={styles.hide}>إخفاء</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
};

const styles = StyleSheet.create({
  overlay: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    paddingHorizontal: Spacing.lg,
    backgroundColor: Palette.overlay,
  },
  card: {
    width: '100%',
    maxWidth: 520,
    maxHeight: '85%',
    padding: Spacing.xl,
    borderRadius: Radius.lg,
    backgroundColor: Palette.surface,
  },
  heading: {
    ...Type.section,
    ...textDirection,
    color: Palette.text,
    marginBottom: Spacing.md,
  },
  items: {gap: Spacing.sm},
  row: {...rtlRowStyle, alignItems: 'center', gap: Spacing.sm},
  copy: {flex: 1, minWidth: 0},
  title: {...Type.body, ...textDirection, color: Palette.text},
  secondary: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginVertical: Spacing.xs,
  },
  button: {
    minWidth: 48,
    minHeight: 48,
    justifyContent: 'center',
    alignItems: 'center',
    paddingHorizontal: Spacing.xs,
  },
  cancel: {...Type.bodyStrong, ...textDirection, color: Palette.danger},
  hide: {...Type.button, ...textDirection, color: Palette.text},
});
