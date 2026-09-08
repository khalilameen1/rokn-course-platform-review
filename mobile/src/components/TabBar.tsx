import {useNavigation, useRoute} from '@react-navigation/native';
import type {RootNavigation} from '../navigation/types';
import React, {FC} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import {useSelector} from 'react-redux';

import {useTranslation} from 'react-i18next';
import {
  Colors,
  Fonts,
  PixelPerfect,
  SharedStyles,
} from '../constants/styleConstants';

import {
  FullNameIcon,
  HomeIcon,
  MyCornerIcon,
  MyWalletIcon,
} from '../assets/SVG';
import {
  Accessibility,
  Palette,
  Spacing,
  Type,
  rtlRowStyle,
} from '../constants/designSystem';
import {formatArabicDisplayText} from '../constants/arabicFormatting';
import {selectRootTab} from '../navigation/journeyNavigation';
import {extractApiToken} from '../constants/helpers';
import type {RootState} from '../store/store';

const TabBar: FC = () => {
  const route = useRoute();
  const active = route.name;
  const {t} = useTranslation();
  const navigation = useNavigation<RootNavigation>();
  const authenticated = useSelector((state: RootState) =>
    Boolean(extractApiToken(state.auth.userData)),
  );

  return (
    <View style={styles.allTabsCon}>
      <View style={styles.tabsRow}>
        <Pressable
          accessibilityLabel={t('Home')}
          accessibilityRole="tab"
          accessibilityState={{selected: active === 'Home'}}
          style={[styles.tabCon]}
          onPress={() => {
            selectRootTab(navigation, 'Home', authenticated);
          }}>
          <View style={styles.tabSpace}>
            <HomeIcon
              width={PixelPerfect(24)}
              height={PixelPerfect(24)}
              stroke={active === 'Home' ? Palette.primary : Palette.textMuted}
            />
          </View>
          <Text
            numberOfLines={2}
            style={[
              styles.tabName,
              {
                color: active === 'Home' ? Palette.primary : Palette.textMuted,
              },
            ]}>
            {formatArabicDisplayText(String(t('Home')))}
          </Text>
        </Pressable>
        <Pressable
          accessibilityLabel={t('My Corner')}
          accessibilityRole="tab"
          accessibilityState={{selected: active === 'MyCorner'}}
          style={[styles.tabCon]}
          onPress={() => {
            selectRootTab(navigation, 'MyCorner', authenticated);
          }}>
          <View style={styles.tabSpace}>
            <MyCornerIcon
              width={PixelPerfect(24)}
              height={PixelPerfect(24)}
              fill={active === 'MyCorner' ? Palette.primary : Palette.textMuted}
            />
          </View>
          <Text
            numberOfLines={2}
            style={[
              styles.tabName,
              {
                color:
                  active === 'MyCorner' ? Palette.primary : Palette.textMuted,
              },
            ]}>
            {formatArabicDisplayText(String(t('My Corner')))}
          </Text>
        </Pressable>
        <Pressable
          accessibilityLabel={t('Wallet')}
          accessibilityRole="tab"
          accessibilityState={{selected: active === 'Wallet'}}
          style={[styles.tabCon]}
          onPress={() => {
            selectRootTab(navigation, 'Wallet', authenticated);
          }}>
          <View style={styles.tabSpace}>
            <MyWalletIcon
              width={PixelPerfect(24)}
              height={PixelPerfect(24)}
              stroke={active === 'Wallet' ? Palette.primary : Palette.textMuted}
            />
          </View>
          <Text
            numberOfLines={2}
            style={[
              styles.tabName,
              {
                color:
                  active === 'Wallet' ? Palette.primary : Palette.textMuted,
              },
            ]}>
            {formatArabicDisplayText(String(t('Wallet')))}
          </Text>
        </Pressable>
        <Pressable
          accessibilityLabel={t('Me')}
          accessibilityRole="tab"
          accessibilityState={{selected: active === 'Profile'}}
          style={[styles.tabCon]}
          onPress={() => {
            selectRootTab(navigation, 'Profile', authenticated);
          }}>
          <View style={styles.tabSpace}>
            <FullNameIcon
              width={PixelPerfect(24)}
              height={PixelPerfect(24)}
              stroke={
                active === 'Profile' ? Palette.primary : Palette.textMuted
              }
            />
          </View>
          <Text
            numberOfLines={2}
            style={[
              styles.tabName,
              {
                color:
                  active === 'Profile' ? Palette.primary : Palette.textMuted,
              },
            ]}>
            {formatArabicDisplayText(String(t('Me')))}
          </Text>
        </Pressable>
      </View>
    </View>
  );
};

export default TabBar;
const styles = StyleSheet.create({
  allTabsCon: {
    backgroundColor: Palette.canvasSoft,
    borderTopColor: Palette.lineSoft,
    borderTopWidth: StyleSheet.hairlineWidth,
    width: '100%',
  },
  tabsRow: {
    ...rtlRowStyle,
    justifyContent: 'space-around',
    width: '100%',
    maxWidth: 720,
    alignSelf: 'center',
  },
  tabCon: {
    ...SharedStyles.centred,
    minHeight: Math.max(Accessibility.minTouchTarget, PixelPerfect(58)),
    paddingVertical: Spacing.xs,
    paddingHorizontal: 2,
    flex: 1,
    minWidth: 0,
  },

  tabName: {
    ...Type.caption,
    fontFamily: Fonts.medium,
    color: Colors.white,
    width: '100%',
    textAlign: 'center',
    includeFontPadding: false,
  },
  tabSpace: {
    ...SharedStyles.centred,
    width: PixelPerfect(32),
    height: PixelPerfect(30),
    flexShrink: 0,
  },
  tabIndicator: {
    position: 'absolute',
    height: PixelPerfect(1),

    backgroundColor: Colors.mainColor,

    top: 0,
  },
});
