import React from 'react';
import {Image, StyleSheet, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import RoknCoin, {CoinAmount} from '../src/components/ui/RoknCoin';
import {Palette} from '../src/constants/designSystem';
import {formatArabicNumber} from '../src/constants/arabicFormatting';

describe('Rokn coin presentation', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  afterEach(() => {
    act(() => renderer?.unmount());
    renderer = undefined;
  });

  it.each([15, 18, 30, 58])(
    'fits the approved coin image within its %idp slot',
    size => {
      act(() => {
        renderer = TestRenderer.create(<RoknCoin size={size} />);
      });
      const images = renderer!.root.findAllByType(Image);
      expect(images).toHaveLength(1);
      const coin = images[0];
      expect(coin.props.source).toBe(
        require('../src/assets/images/coins/rokn-coin-minted.png'),
      );
      expect(StyleSheet.flatten(coin.props.style)).toEqual({
        width: size,
        height: size,
      });
      expect(coin.props).toMatchObject({
        resizeMode: 'contain',
        resizeMethod: 'resize',
        fadeDuration: 0,
      });
      expect(coin.parent?.props.accessibilityElementsHidden).toBe(true);
    },
  );

  it('keeps one icon and the unchanged amount with the caller text style', () => {
    act(() => {
      renderer = TestRenderer.create(
        <CoinAmount value={1250} size={18} textStyle={{color: Palette.text}} />,
      );
    });
    expect(renderer!.root.findAllByType(Image)).toHaveLength(1);
    const amount = renderer!.root.findByType(Text);
    expect(amount.props.children).toBe(formatArabicNumber(1250));
    expect(StyleSheet.flatten(amount.props.style).color).toBe(Palette.text);
    expect(
      renderer!.root.findAll(
        node =>
          node.props.accessibilityLabel ===
          `${formatArabicNumber(1250)} من رصيد ركن`,
      ).length,
    ).toBeGreaterThan(0);
  });
});
