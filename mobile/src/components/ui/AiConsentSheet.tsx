import React, {useEffect, useSyncExternalStore} from 'react';
import {
  Linking,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
} from '../../constants/designSystem';
import {useReducedMotion} from '../../hooks/useReducedMotion';
import {aiConsentPromptHost} from '../../services/aiConsentPrompt';
import {privacyPolicyUrl} from '../../services/publicLinks';

export const AI_CONSENT_COPY = {
  title: 'الاستفسارات ومراجعة المشاريع',
  body: 'تستخدم الاستفسارات ومراجعة المشاريع الذكاء الاصطناعي\nنرسل ما تكتبه ومرفقاتك وسياق الكورس والمحادثة إلى مزود الذكاء الاصطناعي للإجابة وتقييم مشروعك',
  footnote: 'يمكنك إيقاف المشاركة لاحقًا من الإعدادات',
  accept: 'أوافق وأتابع',
  later: 'ليس الآن',
  privacy: 'سياسة الخصوصية',
} as const;

const openPrivacyPolicy = () => {
  // Reading the policy is part of deciding, not a decision: it opens in an
  // in-app browser (Custom Tabs / SFSafariViewController) over the sheet and
  // the request stays pending. The browser module loads on first use only.
  import('expo-web-browser')
    .then(browser => browser.openBrowserAsync(privacyPolicyUrl))
    .catch(() => Linking.openURL(privacyPolicyUrl).catch(() => undefined));
};

/**
 * The AI data-sharing consent, in the same bottom-sheet form the app already
 * uses for settings choices (SettingsChoiceModal) with the primary/"later"
 * pair of NotificationPermissionPrimer. Mounted once at the app root.
 */
export const AiConsentPromptHost = () => {
  const request = useSyncExternalStore(
    aiConsentPromptHost.subscribe,
    aiConsentPromptHost.getSnapshot,
    aiConsentPromptHost.getSnapshot,
  );
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  useEffect(() => aiConsentPromptHost.register(), []);

  const decline = () => request?.decide(false);

  return (
    <Modal
      animationType={reducedMotion ? 'none' : 'slide'}
      onRequestClose={decline}
      statusBarTranslucent
      transparent
      visible={Boolean(request)}>
      <View style={styles.overlay}>
        <Pressable
          accessibilityLabel="إغلاق النافذة"
          accessibilityRole="button"
          onPress={decline}
          style={StyleSheet.absoluteFill}
        />
        <View
          accessibilityViewIsModal
          style={[
            styles.sheet,
            {
              paddingBottom: Math.max(Spacing.xl, insets.bottom + Spacing.md),
              paddingLeft: Math.max(Spacing.xl, insets.left + Spacing.md),
              paddingRight: Math.max(Spacing.xl, insets.right + Spacing.md),
            },
          ]}
          testID="ai-consent-sheet">
          <ScrollView bounces={false} showsVerticalScrollIndicator={false}>
            <Text accessibilityRole="header" style={styles.title}>
              {AI_CONSENT_COPY.title}
            </Text>
            <Text style={styles.body}>{AI_CONSENT_COPY.body}</Text>
            <Pressable
              accessibilityHint="تفتح داخل التطبيق ويبقى اختيارك معلقًا"
              accessibilityRole="link"
              hitSlop={8}
              onPress={openPrivacyPolicy}
              style={styles.link}
              testID="ai-consent-privacy">
              <Text style={styles.linkText}>{AI_CONSENT_COPY.privacy}</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              onPress={() => request?.decide(true)}
              style={({pressed}) => [styles.primary, pressed && styles.pressed]}
              testID="ai-consent-accept">
              <Text style={styles.primaryText}>{AI_CONSENT_COPY.accept}</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              onPress={decline}
              style={({pressed}) => [styles.later, pressed && styles.pressed]}
              testID="ai-consent-later">
              <Text style={styles.laterText}>{AI_CONSENT_COPY.later}</Text>
            </Pressable>
            <Text style={styles.footnote}>{AI_CONSENT_COPY.footnote}</Text>
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
  title: {
    ...Type.title,
    ...textDirection,
    color: Palette.text,
  },
  body: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  link: {
    minHeight: Accessibility.minTouchTarget,
    alignSelf: 'flex-start',
    justifyContent: 'center',
  },
  linkText: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.primaryText,
    textDecorationLine: 'underline',
  },
  primary: {
    width: '100%',
    minHeight: 54,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: Spacing.sm,
    paddingHorizontal: Spacing.md,
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
  },
  primaryText: {...Type.button, ...textDirection, color: '#FFFFFF'},
  later: {
    minHeight: Accessibility.minTouchTarget,
    alignSelf: 'center',
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: Spacing.lg,
    marginTop: Spacing.xxs,
  },
  laterText: {...Type.bodyStrong, ...textDirection, color: Palette.textMuted},
  footnote: {
    ...Type.caption,
    ...textDirection,
    alignSelf: 'stretch',
    color: Palette.textFaint,
    textAlign: 'center',
    marginTop: Spacing.xxs,
  },
  pressed: {opacity: 0.72},
});
