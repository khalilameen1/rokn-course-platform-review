import * as AppleAuthentication from 'expo-apple-authentication';
import React from 'react';
import {
  ActivityIndicator,
  Image,
  type ImageSourcePropType,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
  useWindowDimensions,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import Svg, {Path} from 'react-native-svg';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import type {
  SocialAuthMethods,
  SocialProvider,
} from '../../services/socialAuth';

type Props = {
  phase: 'discovering' | 'discovery_failed' | 'ready' | 'authorizing';
  failureCode?: string;
  methods: SocialAuthMethods | null;
  orderedProviderIds: SocialProvider[];
  recommendedProvider: SocialProvider | null | undefined;
  recommendationText: string | null;
  loading: SocialProvider | null;
  onContinue: (provider: SocialProvider) => void;
  onRetry: () => void;
  onExplore: () => void;
  onOpenTerms: () => void;
  onOpenPrivacy: () => void;
};

const providerDefinitions: Array<{
  id: SocialProvider;
  label: string;
  image?: ImageSourcePropType;
  brandMark?: 'tiktok';
}> = [
  {
    id: 'facebook',
    label: 'المتابعة بحساب Facebook',
    image: require('../../assets/images/facebook.png'),
  },
  {
    id: 'google',
    label: 'المتابعة بحساب Google',
    image: require('../../assets/images/google.png'),
  },
  {
    id: 'tiktok',
    label: 'المتابعة بحساب TikTok',
    brandMark: 'tiktok',
  },
  ...(Platform.OS === 'ios'
    ? ([
        {
          id: 'apple',
          label: 'المتابعة بحساب Apple',
        },
      ] as const)
    : []),
];

const TikTokMark = () => (
  <Svg accessibilityElementsHidden width={24} height={24} viewBox="0 0 24 24">
    <Path
      d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.03-.5-.04-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.45 3.98-2.14 6.15-1.74.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-2.98.42-.6.44-1.02 1.11-1.13 1.85-.09.53-.05 1.08.15 1.58.19.47.5.89.91 1.2.78.61 1.9.74 2.79.29.59-.3 1.03-.85 1.16-1.5.07-.25.05-.51.05-.76.01-4.25-.01-8.51.01-12.76z"
      fill="#FFFFFF"
    />
  </Svg>
);

const failureMessage = (failureCode?: string) =>
  failureCode === 'NETWORK_UNAVAILABLE' || failureCode === 'NETWORK_TIMEOUT'
    ? 'تحقق من الاتصال\nثم حاول مرة أخرى'
    : failureCode === 'PROVIDER_UNAVAILABLE'
    ? 'طرق تسجيل الدخول غير متاحة الآن'
    : 'تعذّر تحميل طرق الدخول';

export default function SocialAuthView({
  phase,
  failureCode,
  methods,
  orderedProviderIds,
  recommendedProvider,
  recommendationText,
  loading,
  onContinue,
  onRetry,
  onExplore,
  onOpenTerms,
  onOpenPrivacy,
}: Props) {
  const insets = useSafeAreaInsets();
  const {height} = useWindowDimensions();
  const busy = Boolean(loading) || phase === 'authorizing';
  const dismiss = () => {
    if (!busy) onExplore();
  };
  const orderedProviders = orderedProviderIds.flatMap(providerId => {
    const provider = providerDefinitions.find(item => item.id === providerId);
    return provider ? [provider] : [];
  });

  return (
    <View
      accessibilityViewIsModal
      style={[
        styles.overlay,
        {
          paddingTop: insets.top + Spacing.sm,
          paddingLeft: insets.left,
          paddingRight: insets.right,
        },
      ]}>
      <Pressable
        accessibilityLabel="إغلاق خيارات تسجيل الدخول"
        accessibilityRole="button"
        accessibilityState={{disabled: busy}}
        disabled={busy}
        onPress={dismiss}
        style={styles.backdrop}
      />
      <View
        testID="social-auth-sheet"
        style={[
          styles.sheet,
          {maxHeight: Math.max(0, height - insets.top - Spacing.sm)},
        ]}>
        <ScrollView
          style={styles.scroll}
          contentContainerStyle={{
            paddingBottom: Math.max(insets.bottom, Spacing.md) + Spacing.sm,
          }}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator>
          <View style={styles.header}>
            <View style={styles.heading}>
              <Text accessibilityRole="header" style={styles.title}>
                الدخول إلى ركن
              </Text>
              <Text style={styles.subtitle}>اختر حسابك للمتابعة</Text>
            </View>
            <Pressable
              accessibilityLabel="إغلاق"
              accessibilityRole="button"
              accessibilityState={{disabled: busy}}
              disabled={busy}
              onPress={dismiss}
              style={({pressed}) => [
                styles.closeButton,
                busy && styles.providerDisabled,
                pressed && styles.pressed,
              ]}>
              <Svg
                accessibilityElementsHidden
                width={20}
                height={20}
                viewBox="0 0 24 24">
                <Path
                  d="M6 6l12 12M18 6L6 18"
                  stroke={Palette.text}
                  strokeWidth={2}
                  strokeLinecap="round"
                />
              </Svg>
            </Pressable>
          </View>
          <View style={styles.body}>
            <View style={styles.providers}>
              {orderedProviders.map(provider => {
                const disabled = busy;
                if (provider.id === 'apple') {
                  return (
                    <View
                      key={provider.id}
                      pointerEvents={disabled ? 'none' : 'auto'}
                      style={disabled ? styles.providerDisabled : undefined}>
                      <AppleAuthentication.AppleAuthenticationButton
                        buttonStyle={
                          AppleAuthentication.AppleAuthenticationButtonStyle
                            .WHITE
                        }
                        buttonType={
                          AppleAuthentication.AppleAuthenticationButtonType
                            .CONTINUE
                        }
                        cornerRadius={14}
                        onPress={() => {
                          if (!busy) onContinue('apple');
                        }}
                        style={styles.appleProvider}
                      />
                    </View>
                  );
                }

                return (
                  <View
                    key={provider.id}
                    style={
                      provider.id === recommendedProvider && recommendationText
                        ? styles.recommendedProviderWrap
                        : undefined
                    }>
                    {provider.id === recommendedProvider &&
                      recommendationText && (
                        <View
                          pointerEvents="none"
                          style={styles.recommendedBadge}>
                          <Text
                            maxFontSizeMultiplier={1.6}
                            style={styles.recommendedText}>
                            {recommendationText}
                          </Text>
                        </View>
                      )}
                    <Pressable
                      accessibilityLabel={provider.label}
                      accessibilityRole="button"
                      accessibilityState={{
                        busy: loading === provider.id,
                        disabled,
                      }}
                      disabled={disabled}
                      onPress={() => {
                        if (!busy) onContinue(provider.id);
                      }}
                      style={({pressed}) => [
                        styles.provider,
                        provider.id === 'google' && styles.googleProvider,
                        provider.id === 'tiktok' && styles.tiktokProvider,
                        provider.id === 'facebook' && styles.facebookProvider,
                        loading &&
                          loading !== provider.id &&
                          styles.providerDisabled,
                        pressed && styles.pressed,
                      ]}>
                      <View style={styles.providerIcon}>
                        {loading === provider.id ? (
                          <ActivityIndicator
                            color={
                              provider.id === 'google'
                                ? Palette.canvas
                                : '#FFFFFF'
                            }
                            size="small"
                          />
                        ) : provider.image ? (
                          <Image
                            accessibilityElementsHidden
                            importantForAccessibility="no"
                            source={provider.image}
                            style={styles.providerImage}
                          />
                        ) : provider.brandMark === 'tiktok' ? (
                          <TikTokMark />
                        ) : null}
                      </View>
                      <Text
                        style={[
                          styles.providerLabel,
                          provider.id === 'google' && styles.googleLabel,
                        ]}>
                        {provider.label}
                      </Text>
                    </Pressable>
                  </View>
                );
              })}
            </View>

            {phase === 'discovering' && (
              <View accessibilityLiveRegion="polite" style={styles.authStatus}>
                <ActivityIndicator color={Palette.primary} size="small" />
                <Text style={styles.authStatusText}>جارٍ تحميل طرق الدخول</Text>
              </View>
            )}
            {(phase === 'discovery_failed' ||
              (methods && orderedProviders.length === 0)) && (
              <View accessibilityRole="alert" style={styles.authStatus}>
                <Text style={styles.authStatusText}>
                  {phase === 'discovery_failed'
                    ? failureMessage(failureCode)
                    : 'طرق تسجيل الدخول غير متاحة الآن'}
                </Text>
                <Pressable
                  accessibilityLabel="إعادة تحميل طرق تسجيل الدخول"
                  accessibilityRole="button"
                  accessibilityState={{disabled: busy}}
                  disabled={busy}
                  onPress={onRetry}
                  style={styles.retryMethods}>
                  <Text style={styles.retryMethodsText}>حاول مرة أخرى</Text>
                </Pressable>
              </View>
            )}

            <View
              accessibilityLabel="بالمتابعة أنت توافق على شروط الاستخدام وسياسة الخصوصية"
              style={styles.legal}>
              <Text style={styles.legalCopy}>بالمتابعة أنت توافق على</Text>
              <Pressable
                accessibilityLabel="فتح شروط الاستخدام"
                accessibilityRole="link"
                onPress={onOpenTerms}
                style={styles.legalLinkButton}>
                <Text style={styles.legalLink}>شروط الاستخدام</Text>
              </Pressable>
              <Text style={styles.legalCopy}>و</Text>
              <Pressable
                accessibilityLabel="فتح سياسة الخصوصية"
                accessibilityRole="link"
                onPress={onOpenPrivacy}
                style={styles.legalLinkButton}>
                <Text style={styles.legalLink}>سياسة الخصوصية</Text>
              </Pressable>
            </View>
          </View>
        </ScrollView>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  overlay: {
    flex: 1,
    justifyContent: 'flex-end',
    alignItems: 'center',
    backgroundColor: 'transparent',
  },
  backdrop: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: Palette.overlay,
  },
  sheet: {
    width: '100%',
    maxWidth: 520,
    backgroundColor: Palette.surface,
    borderTopLeftRadius: Radius.xl,
    borderTopRightRadius: Radius.xl,
    overflow: 'hidden',
  },
  scroll: {flexGrow: 0, flexShrink: 1},
  body: {paddingHorizontal: Spacing.xl},
  header: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: Spacing.sm,
    paddingHorizontal: Spacing.xl,
    paddingTop: Spacing.md,
    paddingBottom: Spacing.lg,
  },
  heading: {flex: 1, minWidth: 0},
  closeButton: {
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    flexShrink: 0,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.pill,
    backgroundColor: Palette.surfaceRaised,
  },
  title: {
    ...Type.section,
    ...textDirection,
    color: Palette.text,
  },
  subtitle: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xxs,
  },
  providers: {direction: 'rtl', gap: Spacing.sm},
  recommendedProviderWrap: {direction: 'rtl'},
  provider: {
    minHeight: 58,
    ...rtlRowStyle,
    alignItems: 'center',
    borderRadius: Radius.md,
    borderWidth: 1,
    paddingHorizontal: Spacing.md,
    paddingVertical: Spacing.xs,
  },
  googleProvider: {backgroundColor: '#FFFFFF', borderColor: '#FFFFFF'},
  tiktokProvider: {backgroundColor: '#111111', borderColor: '#30343B'},
  facebookProvider: {backgroundColor: '#1877F2', borderColor: '#1877F2'},
  appleProvider: {width: '100%', height: 58},
  providerIcon: {
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
  },
  providerImage: {width: 27, height: 27, resizeMode: 'contain'},
  providerLabel: {
    ...Type.bodyStrong,
    ...textDirection,
    color: '#FFFFFF',
    flex: 1,
    textAlign: 'center',
  },
  googleLabel: {color: '#202124'},
  recommendedBadge: {
    zIndex: 2,
    alignSelf: 'flex-end',
    marginEnd: 14,
    marginBottom: -6,
    maxWidth: '86%',
    minHeight: 24,
    justifyContent: 'center',
    borderRadius: Radius.pill,
    paddingHorizontal: 10,
    paddingVertical: 1,
    backgroundColor: '#682F39',
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: '#B97862',
  },
  recommendedText: {
    fontFamily: 'Cairo-SemiBold',
    fontSize: 11,
    lineHeight: 18,
    direction: 'rtl',
    writingDirection: 'rtl',
    textAlign: 'center',
    color: '#FFE9DF',
    flexShrink: 1,
  },
  legal: {
    ...rtlRowStyle,
    flexWrap: 'wrap',
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: Spacing.xl,
  },
  legalCopy: {...Type.caption, ...textDirection, color: Palette.textFaint},
  legalLinkButton: {
    minHeight: Accessibility.minTouchTarget,
    justifyContent: 'center',
    paddingHorizontal: Spacing.xs,
  },
  legalLink: {
    ...Type.caption,
    ...textDirection,
    color: '#8BB5FF',
    textDecorationLine: 'underline',
  },
  authStatus: {
    minHeight: 32,
    ...rtlRowStyle,
    flexWrap: 'wrap',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 7,
    marginTop: Spacing.sm,
  },
  authStatusText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    textAlign: 'center',
  },
  retryMethods: {
    minHeight: Accessibility.minTouchTarget,
    justifyContent: 'center',
    paddingHorizontal: Spacing.sm,
    borderRadius: Radius.pill,
    backgroundColor: Palette.primarySoft,
  },
  retryMethodsText: {...Type.caption, color: '#8BB5FF'},
  providerDisabled: {opacity: 0.46},
  pressed: {opacity: 0.78, transform: [{scale: 0.99}]},
});
