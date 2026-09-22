import React from 'react';
import {StyleSheet, View} from 'react-native';
import Svg, {Circle, Path, Rect} from 'react-native-svg';
import {Palette} from '../../constants/designSystem';

type Kind =
  | 'guide'
  | 'bell'
  | 'instagram'
  | 'facebook'
  | 'youtube'
  | 'tiktok'
  | 'whatsapp'
  | 'share'
  | 'link';

const detectKind = (value: string): Kind => {
  const text = value.toLowerCase();
  if (text.includes('coin-guide') || text.includes('coin_guide'))
    return 'guide';
  if (text.includes('notification') || text.includes('إشعار')) return 'bell';
  if (
    text.includes('instagram') ||
    text.includes('انست') ||
    text.includes('إنست')
  )
    return 'instagram';
  if (text.includes('facebook') || text.includes('فيس')) return 'facebook';
  if (text.includes('youtube') || text.includes('يوتيوب')) return 'youtube';
  if (text.includes('tiktok') || text.includes('تيك')) return 'tiktok';
  if (text.includes('whatsapp') || text.includes('واتس')) return 'whatsapp';
  if (text.includes('share') || text.includes('شارك')) return 'share';
  return 'link';
};

export default function TaskBrandIcon({
  value,
  plain = false,
}: {
  value: string;
  plain?: boolean;
}) {
  const kind = detectKind(value);
  const stroke = plain ? Palette.text : '#E9C66F';
  return (
    <View
      accessibilityElementsHidden
      style={[styles.container, plain && styles.plain]}>
      <Svg width={22} height={22} viewBox="0 0 24 24" fill="none">
        {kind === 'guide' && (
          <>
            <Path
              d="M4 5.5h5a3 3 0 0 1 3 3v10.5a3.5 3.5 0 0 0-3-1.5H4v-12Z"
              stroke={stroke}
              strokeWidth={1.8}
              strokeLinecap="round"
              strokeLinejoin="round"
            />
            <Path
              d="M20 5.5h-5a3 3 0 0 0-3 3v10.5a3.5 3.5 0 0 1 3-1.5h5v-12Z"
              stroke={stroke}
              strokeWidth={1.8}
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </>
        )}
        {kind === 'bell' && (
          <Path
            d="M6 17h12l-1.4-1.7V11a4.6 4.6 0 0 0-9.2 0v4.3L6 17Zm4 2h4"
            stroke={stroke}
            strokeWidth={1.8}
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        )}
        {kind === 'instagram' && (
          <>
            <Rect
              x={3.5}
              y={3.5}
              width={17}
              height={17}
              rx={5}
              stroke={stroke}
              strokeWidth={1.8}
            />
            <Circle cx={12} cy={12} r={3.7} stroke={stroke} strokeWidth={1.8} />
            <Circle cx={17.3} cy={6.8} r={1} fill={stroke} />
          </>
        )}
        {kind === 'facebook' && (
          <Path
            d="M13.4 20v-7h2.4l.4-2.8h-2.8V8.4c0-.8.2-1.4 1.4-1.4h1.5V4.5c-.3 0-1.2-.1-2.2-.1-2.2 0-3.7 1.3-3.7 3.8v2H8V13h2.4v7h3Z"
            fill={stroke}
          />
        )}
        {kind === 'youtube' && (
          <>
            <Rect
              x={2.8}
              y={6.2}
              width={18.4}
              height={11.6}
              rx={3.4}
              stroke={stroke}
              strokeWidth={1.8}
            />
            <Path d="m10 9 5 3-5 3V9Z" fill={stroke} />
          </>
        )}
        {kind === 'tiktok' && (
          <Path
            d="M14.4 4v9.1a3.8 3.8 0 1 1-3.2-3.75v2.55a1.45 1.45 0 1 0 .75 1.27V4h2.45Zm0 1.3c.65 1.35 1.6 2.15 3.1 2.45v2.45c-1.3-.08-2.25-.45-3.1-1.1V5.3Z"
            fill={stroke}
          />
        )}
        {kind === 'whatsapp' && (
          <>
            <Path
              d="M19.5 11.5a7.5 7.5 0 0 1-11 6.6L4.5 19.5l1.3-3.9a7.5 7.5 0 1 1 13.7-4.1Z"
              stroke={stroke}
              strokeWidth={1.7}
              strokeLinejoin="round"
            />
            <Path
              d="M9 8.4c.4 2.8 1.8 4.2 4.6 5l1-1.1 1.7.8c-.1 1.4-.8 2.1-2.1 2.1-3.6-.2-6.9-3.4-7.1-7 0-1.2.7-1.9 2-2l.8 1.7-.9.5Z"
              fill={stroke}
            />
          </>
        )}
        {kind === 'share' && (
          <>
            <Circle cx={6} cy={12} r={2.2} stroke={stroke} strokeWidth={1.7} />
            <Circle cx={18} cy={6} r={2.2} stroke={stroke} strokeWidth={1.7} />
            <Circle cx={18} cy={18} r={2.2} stroke={stroke} strokeWidth={1.7} />
            <Path d="m8 11 8-4M8 13l8 4" stroke={stroke} strokeWidth={1.7} />
          </>
        )}
        {kind === 'link' && (
          <Path
            d="m9.5 14.5 5-5M8 17H6.5a3.5 3.5 0 0 1 0-7H9m6 4h2.5a3.5 3.5 0 0 0 0-7H16"
            stroke={stroke}
            strokeWidth={1.8}
            strokeLinecap="round"
          />
        )}
      </Svg>
    </View>
  );
}

const styles = StyleSheet.create({
  plain: {backgroundColor: 'transparent'},
  container: {
    width: 38,
    height: 38,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.coinSoft,
  },
});
