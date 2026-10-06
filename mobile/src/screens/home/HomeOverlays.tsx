import React from 'react';
import {
  ImageSourcePropType,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {RasterImage as Image} from '../../components/ui/RasterImage';
import {CoinAmount} from '../../components/ui/RoknCoin';
import {AppArtwork} from '../../components/ui/AppArtwork';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {formatAuthoredDisplayText} from '../../constants/arabicFormatting';
import {Fonts} from '../../constants/styleConstants';
import {
  Accessibility,
  Palette,
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
  badge?: string;
};
type Props = {
  campaign: HomeCampaign | null;
  campaignImageFailed: boolean;
  onCampaignImageError: () => void;
  onDismissCampaign: (open: boolean) => void;
  guestPrompt: EngagementMessage | null;
  onDismissGuestPrompt: () => void;
  onOpenGuestPrompt: () => void;
};
/** Approved composition rendered by RN Modal, not a parallel overlay stack. */
export const HomeOverlays = ({
  campaign,
  campaignImageFailed,
  onCampaignImageError,
  onDismissCampaign,
  guestPrompt,
  onDismissGuestPrompt,
  onOpenGuestPrompt,
}: Props) => {
  const reducedMotion = useReducedMotion();
  const insets = useSafeAreaInsets();
  const gift = guestPrompt !== null;
  const title = gift ? guestPrompt.title : campaign?.title;
  const dismiss = () =>
    gift ? onDismissGuestPrompt() : onDismissCampaign(false);
  return (
    <Modal
      animationType={reducedMotion ? 'none' : 'fade'}
      onRequestClose={dismiss}
      statusBarTranslucent
      transparent
      visible={gift || campaign !== null}>
      <View style={styles.overlay}>
        <ScrollView
          bounces={false}
          showsVerticalScrollIndicator={false}
          contentContainerStyle={[
            styles.overlayContent,
            {
              paddingTop: Math.max(insets.top + 16, 24),
              paddingBottom: Math.max(insets.bottom + 16, 24),
              paddingStart: insets.right + 15,
              paddingEnd: insets.left + 15,
            },
          ]}>
          <View
            accessibilityViewIsModal
            testID="home-announcement-card"
            style={[styles.card, gift && styles.giftCard]}>
            <Pressable
              accessibilityLabel="إغلاق"
              accessibilityRole="button"
              onPress={dismiss}
              style={styles.close}>
              <Text style={styles.closeText}>×</Text>
            </Pressable>
            {gift ? (
              <View style={styles.giftVisual}>
                <AppArtwork
                  asset="coin_stack"
                  uri={guestPrompt.imageUrl}
                  accessibilityElementsHidden
                  importantForAccessibility="no"
                  style={styles.giftArt}
                  defaultArtworkStyle={styles.defaultGiftArt}
                  resizeMode="contain"
                />
              </View>
            ) : (
              <View style={styles.courseVisual}>
                {campaign?.image && !campaignImageFailed && (
                  <Image
                    accessibilityIgnoresInvertColors
                    onError={onCampaignImageError}
                    source={campaign.image}
                    style={styles.cover}
                    resizeMode="cover"
                  />
                )}
                {!!campaign?.badge && (
                  <View style={styles.badge}>
                    <Text style={styles.badgeText}>{campaign.badge}</Text>
                  </View>
                )}
              </View>
            )}
            <Text
              accessibilityRole="header"
              style={[styles.title, gift && styles.giftTitle]}>
              {formatAuthoredDisplayText(title)}
            </Text>
            {gift ? (
              <CoinAmount
                value={guestPrompt.coins}
                size={28}
                style={styles.amount}
                textStyle={styles.amountText}
              />
            ) : (
              !!campaign?.description && (
                <Text style={styles.description}>
                  {formatAuthoredDisplayText(campaign.description)}
                </Text>
              )
            )}
            <Pressable
              accessibilityRole="button"
              onPress={gift ? onOpenGuestPrompt : () => onDismissCampaign(true)}
              style={({pressed}) => [
                styles.actionButton,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.actionText}>
                {gift ? guestPrompt.actionLabel : campaign?.actionLabel}
              </Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              onPress={dismiss}
              style={styles.secondaryButton}>
              <Text style={styles.secondaryText}>
                {gift ? guestPrompt.secondaryActionLabel : 'ليس الآن'}
              </Text>
            </Pressable>
          </View>
        </ScrollView>
      </View>
    </Modal>
  );
};
const styles = StyleSheet.create({
  overlay: {flex: 1, backgroundColor: 'rgba(3,8,16,0.56)'},
  overlayContent: {flexGrow: 1, alignItems: 'center', justifyContent: 'center'},
  card: {
    width: '100%',
    maxWidth: 400,
    paddingHorizontal: 20,
    paddingTop: 52,
    paddingBottom: 10,
    borderRadius: 24,
    backgroundColor: '#142033',
    borderColor: '#2C3A50',
    borderWidth: 1,
  },
  giftCard: {minHeight: 304, paddingTop: 44, alignItems: 'center'},
  close: {
    position: 'absolute',
    top: 3,
    end: 5,
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
  },
  closeText: {fontSize: 23, lineHeight: 23, color: '#B4C0D2'},
  giftVisual: {
    height: 104,
    width: '100%',
    alignItems: 'center',
    justifyContent: 'center',
    overflow: 'hidden',
  },
  giftArt: {width: '100%', maxWidth: 200, height: 104},
  // Only the shipped coin stack has known transparent padding. Its approved
  // 200px framing crops that padding, not arbitrary dashboard artwork.
  defaultGiftArt: {height: 200},
  courseVisual: {
    width: '100%',
    aspectRatio: 16 / 9,
    borderRadius: 13,
    backgroundColor: Palette.surfaceRaised,
  },
  cover: {width: '100%', height: '100%', borderRadius: 13},
  badge: {
    position: 'absolute',
    top: 10,
    start: 10,
    borderRadius: 6,
    paddingHorizontal: 10,
    paddingVertical: 4,
    backgroundColor: Palette.primary,
  },
  badgeText: {
    fontFamily: Fonts.bold,
    fontSize: 11,
    lineHeight: 18,
    color: '#FFFFFF',
  },
  title: {
    fontFamily: Fonts.bold,
    fontSize: 16,
    lineHeight: 25,
    ...textDirection,
    color: Palette.text,
    marginTop: 14,
    marginBottom: 12,
  },
  giftTitle: {textAlign: 'center', marginTop: 1, marginBottom: 2},
  amount: {
    alignSelf: 'center',
    justifyContent: 'center',
    marginTop: 4,
    marginBottom: 12,
  },
  amountText: {
    fontFamily: Fonts.extraBold,
    fontSize: 34,
    lineHeight: 41,
    includeFontPadding: false,
    color: '#FFFFFF',
  },
  description: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  actionButton: {
    width: '100%',
    minHeight: 48,
    paddingHorizontal: 11,
    paddingVertical: 11,
    borderRadius: 13,
    backgroundColor: Palette.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  actionText: {
    fontFamily: Fonts.black,
    fontSize: 12,
    lineHeight: 20,
    ...textDirection,
    color: '#FFFFFF',
    textAlign: 'center',
  },
  secondaryButton: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 16,
    paddingVertical: 10,
  },
  secondaryText: {
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
    color: '#B4C0D2',
  },
  pressed: {opacity: 0.75},
});
