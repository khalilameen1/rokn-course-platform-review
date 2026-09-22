import React from 'react';
import {Image, StyleSheet, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {WalletBalanceDetails} from '../src/screens/wallet/WalletBalanceDetails';
import {walletStyles} from '../src/screens/wallet/walletStyles';
import {formatArabicNumber} from '../src/constants/arabicFormatting';
import {Accessibility} from '../src/constants/designSystem';

const values = {
  balance: 1250,
  paidBalance: 1000,
  rewardBalance: 250,
  spendableBalance: 1100,
  rewardContributionCap: 100,
  stacked: false,
};

describe('compact wallet balance details', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const render = async (overrides: Partial<typeof values> = {}) => {
    await act(async () => {
      renderer = TestRenderer.create(
        <WalletBalanceDetails {...values} {...overrides} />,
      );
    });
    return renderer.root;
  };
  const copy = () =>
    renderer.root
      .findAllByType(Text)
      .map(node => React.Children.toArray(node.props.children).join(''))
      .join('\n');

  afterEach(async () => {
    await act(async () => renderer?.unmount());
  });

  it('keeps every server amount and one currency mark without repeated subtitles', async () => {
    const root = await render();
    const texts = root.findAllByType(Text);
    expect(root.findAllByType(Image)).toHaveLength(1);
    expect(texts).toHaveLength(7);
    for (const amount of [1250, 1000, 250]) {
      expect(
        texts.filter(
          node => node.props.children === formatArabicNumber(amount),
        ),
      ).toHaveLength(1);
    }
    for (const label of ['رصيد مدفوع', 'مكافآتك']) {
      expect(texts.filter(node => node.props.children === label)).toHaveLength(
        1,
      );
    }
    for (const retired of [
      'من عمليات الشحن',
      'ترحيب ومهام',
      'بعد تطبيق حد المكافآت',
    ]) {
      expect(copy()).not.toContain(retired);
    }
    expect(copy()).not.toContain('المتاح لكورس واحد');
    expect(copy()).toContain('الخصم والمبلغ المطلوب يظهران عند اختيار الاشتراك');
    expect(walletStyles.bucketRow.minHeight).toBeGreaterThanOrEqual(
      Accessibility.minTouchTarget,
    );
  });

  it('keeps labels and units together for screen readers', async () => {
    const root = await render();
    for (const [label, amount] of [
      ['رصيد مدفوع', 1000],
      ['مكافآتك', 250],
    ] as const) {
      const row = root.findAll(
        node =>
          node.props.accessibilityRole === 'text' &&
          node.props.accessibilityLabel ===
            `${label} ${formatArabicNumber(amount)} من عملات ركن`,
      )[0];
      expect(row.props.accessible).toBe(true);
    }
  });

  it('does not present the legacy cap as a course quote or an entitlement', async () => {
    await render({rewardContributionCap: 0, spendableBalance: 1000});
    expect(copy()).not.toContain('المكافآت غير متاحة للدفع حاليًا');
    expect(copy()).not.toContain('المكافآت أولًا');
    expect(copy()).toContain('الخصم والمبلغ المطلوب يظهران عند اختيار الاشتراك');
  });

  it('shows an unavailable total as unavailable rather than zero', async () => {
    await act(async () => {
      renderer = TestRenderer.create(
        <WalletBalanceDetails {...values} balance={null} />,
      );
    });
    expect(copy()).toContain('—');
  });

  it('gives long balances their own line at narrow widths or enlarged text', async () => {
    const root = await render({
      balance: 123456789,
      paidBalance: 123450000,
      rewardBalance: 6789,
      spendableBalance: 123450100,
      stacked: true,
    });
    for (const text of root.findAllByType(Text)) {
      expect(text.props.numberOfLines).toBeUndefined();
      expect(text.props.adjustsFontSizeToFit).toBeUndefined();
      expect(text.props.maxFontSizeMultiplier).toBeUndefined();
      expect(text.props.allowFontScaling).not.toBe(false);
    }
    const row = root.findAll(
      node =>
        node.props.accessibilityRole === 'text' &&
          node.props.accessibilityLabel?.startsWith('رصيد مدفوع'),
    )[0];
    expect(StyleSheet.flatten(row.props.style).flexDirection).toBe('column');
    expect(StyleSheet.flatten(row.props.style).alignItems).toBe('stretch');
  });
});
