import React, {useEffect, useState} from 'react';
import {
  Image,
  ImageSourcePropType,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {formatAuthoredDisplayText} from '../../constants/arabicFormatting';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
} from '../../constants/designSystem';
import type {EngagementMessage} from '../../services/api/engagement';
import {useReducedMotion} from '../../hooks/useReducedMotion';

export type HomeCampaign = {
  id: string;
  title: string;
  description: string;
  courseId?: string;
  image?: ImageSourcePropType;
  actionLabel: string;
};

type Props = {
  campaign: HomeCampaign | null;
  campaignImageFailed: boolean;
  onCampaignImageError: () => void;
  onDismissCampaign: (open: boolean) => void;
  onDismissWelcome: () => void;
  onOpenWelcome: () => void;
  guestPrompt: EngagementMessage | null;
  onDismissGuestPrompt: () => void;
  onOpenGuestPrompt: () => void;
  welcomeMessage: EngagementMessage | null;
  rewardPrompt: EngagementMessage | null;
  onDismissRewardPrompt: () => void;
  onOpenRewardPrompt: () => void;
};

const OverlayFrame = ({children}: {children: React.ReactNode}) => {
  const insets = useSafeAreaInsets();
  return (
    <View style={styles.overlay}>
      <ScrollView
        bounces={false}
        contentContainerStyle={[
          styles.overlayContent,
          {
            paddingTop: Math.max(insets.top, Spacing.lg),
            paddingBottom: Math.max(insets.bottom, Spacing.lg),
            paddingLeft: Math.max(insets.left + Spacing.md, Spacing.xl),
            paddingRight: Math.max(insets.right + Spacing.md, Spacing.xl),
          },
        ]}
        showsVerticalScrollIndicator={false}>
        {children}
      </ScrollView>
    </View>
  );
};

const PromptVisual = ({uri}: {uri?: string}) => {
  const [failed, setFailed] = useState(false);
  useEffect(() => setFailed(false), [uri]);
  return uri && !failed ? (
    <Image
      accessibilityElementsHidden
      importantForAccessibility="no"
      onError={() => setFailed(true)}
      progressiveRenderingEnabled
      resizeMethod="resize"
      source={{uri}}
      style={styles.promptImage}
    />
  ) : null;
};

export const HomeOverlays = ({
  campaign,
  campaignImageFailed,
  onCampaignImageError,
  onDismissCampaign,
  onDismissWelcome,
  onOpenWelcome,
  guestPrompt,
  onDismissGuestPrompt,
  onOpenGuestPrompt,
  welcomeMessage,
  rewardPrompt,
  onDismissRewardPrompt,
  onOpenRewardPrompt,
}: Props) => {
  const reducedMotion = useReducedMotion();
  return (
    <>
      <Modal
        animationType={reducedMotion ? 'none' : 'fade'}
        onRequestClose={onDismissWelcome}
        statusBarTranslucent
        transparent
        visible={welcomeMessage !== null}>
        <OverlayFrame>
          <View accessibilityViewIsModal style={styles.welcomeCard}>
            <PromptVisual uri={welcomeMessage?.imageUrl} />
            <Text accessibilityRole="header" style={styles.welcomeTitle}>
              {welcomeMessage?.title}
            </Text>
            <Text style={styles.welcomeText}>
              {welcomeMessage?.description}
            </Text>
            <Pressable
              accessibilityRole="button"
              onPress={onOpenWelcome}
              style={({pressed}) => [
                styles.actionButton,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.actionButtonText}>
                {welcomeMessage?.actionLabel}
              </Text>
            </Pressable>
          </View>
        </OverlayFrame>
      </Modal>

      <Modal
        animationType={reducedMotion ? 'none' : 'fade'}
        onRequestClose={
          rewardPrompt?.dismissible ? onDismissRewardPrompt : () => undefined
        }
        statusBarTranslucent
        transparent
        visible={rewardPrompt !== null}>
        <OverlayFrame>
          <View accessibilityViewIsModal style={styles.welcomeCard}>
            <PromptVisual uri={rewardPrompt?.imageUrl} />
            <Text accessibilityRole="header" style={styles.welcomeTitle}>
              {rewardPrompt?.title}
            </Text>
            <Text style={styles.welcomeText}>{rewardPrompt?.description}</Text>
            <Pressable
              accessibilityRole="button"
              onPress={onOpenRewardPrompt}
              style={({pressed}) => [
                styles.actionButton,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.actionButtonText}>
                {rewardPrompt?.actionLabel}
              </Text>
            </Pressable>
            {rewardPrompt?.dismissible && (
              <Pressable
                accessibilityRole="button"
                onPress={onDismissRewardPrompt}
                style={({pressed}) => [
                  styles.secondaryButton,
                  pressed && styles.pressed,
                ]}>
                <Text style={styles.secondaryButtonText}>
                  {rewardPrompt.secondaryActionLabel}
                </Text>
              </Pressable>
            )}
          </View>
        </OverlayFrame>
      </Modal>

      <Modal
        animationType={reducedMotion ? 'none' : 'fade'}
        onRequestClose={
          guestPrompt?.dismissible ? onDismissGuestPrompt : () => undefined
        }
        statusBarTranslucent
        transparent
        visible={guestPrompt !== null}>
        <OverlayFrame>
          <View accessibilityViewIsModal style={styles.welcomeCard}>
            <PromptVisual uri={guestPrompt?.imageUrl} />
            <Text accessibilityRole="header" style={styles.welcomeTitle}>
              {guestPrompt?.title}
            </Text>
            <Text style={styles.welcomeText}>{guestPrompt?.description}</Text>
            <Pressable
              accessibilityRole="button"
              onPress={onOpenGuestPrompt}
              style={({pressed}) => [
                styles.actionButton,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.actionButtonText}>
                {guestPrompt?.actionLabel}
              </Text>
            </Pressable>
            {guestPrompt?.dismissible && (
              <Pressable
                accessibilityRole="button"
                onPress={onDismissGuestPrompt}
                style={({pressed}) => [
                  styles.secondaryButton,
                  pressed && styles.pressed,
                ]}>
                <Text style={styles.secondaryButtonText}>
                  {guestPrompt.secondaryActionLabel}
                </Text>
              </Pressable>
            )}
          </View>
        </OverlayFrame>
      </Modal>

      <Modal
        animationType={reducedMotion ? 'none' : 'fade'}
        onRequestClose={() => onDismissCampaign(false)}
        statusBarTranslucent
        transparent
        visible={campaign !== null}>
        <OverlayFrame>
          <View accessibilityViewIsModal style={styles.campaignCard}>
            {campaign?.image && !campaignImageFailed ? (
              <View style={styles.campaignVisual}>
                <Image
                  accessibilityIgnoresInvertColors
                  onError={onCampaignImageError}
                  progressiveRenderingEnabled
                  resizeMethod="resize"
                  source={campaign.image}
                  style={styles.campaignCourseImage}
                />
              </View>
            ) : null}
            <Pressable
              accessibilityLabel="إغلاق"
              accessibilityRole="button"
              hitSlop={10}
              onPress={() => onDismissCampaign(false)}
              style={styles.campaignClose}>
              <Text style={styles.campaignCloseText}>×</Text>
            </Pressable>
            <Text accessibilityRole="header" style={styles.campaignTitle}>
              {formatAuthoredDisplayText(campaign?.title)}
            </Text>
            <Text style={styles.campaignText}>
              {formatAuthoredDisplayText(campaign?.description)}
            </Text>
            <Pressable
              accessibilityRole="button"
              onPress={() => onDismissCampaign(true)}
              style={({pressed}) => [
                styles.actionButton,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.actionButtonText}>
                {campaign?.actionLabel}
              </Text>
            </Pressable>
          </View>
        </OverlayFrame>
      </Modal>
    </>
  );
};

const styles = StyleSheet.create({
  overlay: {
    flex: 1,
    backgroundColor: Palette.overlay,
  },
  overlayContent: {
    flexGrow: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: Spacing.xl,
  },
  welcomeCard: {
    width: '100%',
    maxWidth: 440,
    alignItems: 'center',
    padding: Spacing.xl,
    borderRadius: Radius.xl,
    borderWidth: 1,
    borderColor: 'rgba(216,166,60,0.28)',
    backgroundColor: Palette.surfaceRaised,
  },
  welcomeTitle: {
    ...Type.title,
    ...textDirection,
    color: Palette.text,
    textAlign: 'center',
    marginTop: Spacing.xs,
  },
  welcomeText: {
    ...Type.body,
    writingDirection: 'rtl',
    color: Palette.textMuted,
    textAlign: 'center',
    marginTop: Spacing.xs,
  },
  actionButton: {
    width: '100%',
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: Spacing.lg,
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
  },
  actionButtonText: {...Type.bodyStrong, color: '#FFFFFF'},
  secondaryButton: {
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: Spacing.xs,
    paddingHorizontal: Spacing.lg,
  },
  secondaryButtonText: {...Type.bodyStrong, color: Palette.textMuted},
  promptImage: {
    width: 112,
    height: 92,
    borderRadius: Radius.md,
    resizeMode: 'cover',
  },
  campaignCard: {
    width: '100%',
    maxWidth: 440,
    alignItems: 'center',
    padding: Spacing.xl,
    paddingTop: Spacing.xxl,
    borderRadius: Radius.xl,
    borderWidth: 1,
    borderColor: 'rgba(216,166,60,0.22)',
    backgroundColor: Palette.surfaceRaised,
  },
  campaignClose: {
    position: 'absolute',
    top: Spacing.sm,
    right: Spacing.sm,
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
  },
  campaignCloseText: {fontSize: 30, lineHeight: 34, color: Palette.textMuted},
  campaignVisual: {
    width: 108,
    height: 88,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: Spacing.xs,
  },
  campaignCourseImage: {
    width: 108,
    height: 88,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    resizeMode: 'cover',
  },
  campaignTitle: {
    ...Type.title,
    writingDirection: 'rtl',
    color: Palette.text,
    textAlign: 'center',
    marginTop: Spacing.lg,
  },
  campaignText: {
    ...Type.body,
    writingDirection: 'rtl',
    color: Palette.textMuted,
    textAlign: 'center',
    marginTop: Spacing.sm,
  },
  pressed: {opacity: 0.75},
});
