import {
  BottomTabBarHeightCallbackContext,
  type BottomTabBarProps,
} from '@react-navigation/bottom-tabs';
import {CommonActions} from '@react-navigation/native';
import React, {FC, useContext} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';

import {useTranslation} from 'react-i18next';
import {
  Colors,
  Fonts,
  PixelPerfect,
  SharedStyles,
} from '../constants/styleConstants';

import {FullNameIcon, HomeIcon, MyCornerIcon, RewardsIcon} from '../assets/SVG';
import {
  Accessibility,
  Palette,
  Spacing,
  Type,
  rtlRowStyle,
} from '../constants/designSystem';
import {formatArabicDisplayText} from '../constants/arabicFormatting';
import type {RootTabName} from '../navigation/types';

const ICON_SIZE = PixelPerfect(24);

/** Label key and icon of each tab, in the approved RTL tab-bar order. */
const TAB_ITEMS: Record<
  RootTabName,
  {label: string; icon: (color: string) => React.ReactNode}
> = {
  Home: {
    label: 'Home',
    icon: color => (
      <HomeIcon width={ICON_SIZE} height={ICON_SIZE} stroke={color} />
    ),
  },
  MyCorner: {
    label: 'My Corner',
    icon: color => (
      <MyCornerIcon width={ICON_SIZE} height={ICON_SIZE} fill={color} />
    ),
  },
  Wallet: {
    label: 'Wallet',
    icon: color => (
      <RewardsIcon width={ICON_SIZE} height={ICON_SIZE} stroke={color} />
    ),
  },
  Profile: {
    label: 'Me',
    icon: color => (
      <FullNameIcon width={ICON_SIZE} height={ICON_SIZE} stroke={color} />
    ),
  },
};

/**
 * Rokn's approved bottom bar, rendered once by the bottom-tab navigator's
 * `tabBar` prop. Press handling follows React Navigation's own BottomTabBar:
 * emit a preventable `tabPress`, then navigate to an unfocused tab inside this
 * navigator (the guest login gate is a `tabPress` listener in Navigation).
 * Like the stock bar it owns the bottom and side safe-area insets.
 */
const TabBar: FC<BottomTabBarProps> = ({state, navigation, insets}) => {
  const {t} = useTranslation();
  const onHeightChange = useContext(BottomTabBarHeightCallbackContext);

  return (
    <View
      onLayout={event => onHeightChange?.(event.nativeEvent.layout.height)}
      style={[
        styles.safeArea,
        {
          paddingBottom: insets.bottom,
          paddingLeft: insets.left,
          paddingRight: insets.right,
        },
      ]}>
      <View style={styles.allTabsCon}>
        <View style={styles.tabsRow}>
          {state.routes.map((route, index) => {
            const item = TAB_ITEMS[route.name as RootTabName];
            if (!item) return null;
            const focused = state.index === index;
            const color = focused ? Palette.primaryText : Palette.textMuted;
            return (
              <Pressable
                key={route.key}
                accessibilityLabel={t(item.label)}
                accessibilityRole="tab"
                accessibilityState={{selected: focused}}
                style={[styles.tabCon]}
                onPress={() => {
                  const event = navigation.emit({
                    type: 'tabPress',
                    target: route.key,
                    canPreventDefault: true,
                    data: {
                      behavior: {scrollToTop: focused, popToTop: focused},
                    },
                  });
                  if (!focused && !event.defaultPrevented) {
                    navigation.dispatch({
                      ...CommonActions.navigate(route.name, route.params),
                      target: state.key,
                    });
                  }
                }}>
                <View style={styles.tabSpace}>{item.icon(color)}</View>
                <Text numberOfLines={2} style={[styles.tabName, {color}]}>
                  {formatArabicDisplayText(String(t(item.label)))}
                </Text>
              </Pressable>
            );
          })}
        </View>
      </View>
    </View>
  );
};

export default TabBar;
const styles = StyleSheet.create({
  // The page canvas below the bar, as when each screen's safe area held it.
  safeArea: {backgroundColor: Palette.canvas},
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
