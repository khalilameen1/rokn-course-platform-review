import React from 'react';
import {StyleSheet, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {CoinAmount} from '../src/components/ui/RoknCoin';
import {Palette} from '../src/constants/designSystem';
import {formatArabicNumber} from '../src/constants/arabicFormatting';
import {TopupStep} from '../src/screens/CourseDetails/details/PurchaseDialogSteps';
import {derivePurchaseTerms} from '../src/screens/CourseDetails/details/purchaseTerms';
import styles from '../src/screens/CourseDetails/details/styles';
import type {CoinPackage} from '../src/services/api/coinPackageMapper';
import type {CourseAccessPlan} from '../src/services/roknApi';
import {cleanUnicodeText} from '../src/utils/unicodeText';

jest.mock('../src/assets/SVG', () => ({MoreSectionArrowLeft: () => null}));
jest.mock('../src/components/ui/RoknCoin', () => ({CoinAmount: () => null}));

const selectedPlan: CourseAccessPlan = {
  code: 'guided',
  name: 'التعلّم بإرشاد',
  priceCoins: 700,
  chatEnabled: true,
  chatMessageLimit: 25,
  projectFeedbackLevel: 'report',
  projectReportEnabled: true,
  projectOutputEnabled: false,
  certificateEnabled: true,
};
const packages: CoinPackage[] = [
  {id: 'exact', label: 'الباقة المناسبة', coins: 300, price: 50},
  {id: 'larger', label: 'باقة أكبر', coins: 500, price: 80},
];
const cappedTerms = derivePurchaseTerms({
  balance: 1000,
  paidBalance: 100,
  rewardBalance: 900,
  rewardContributionLimit: 300,
  minimumPaidCoins: 400,
  price: 700,
  packages,
});

describe('course top-up presentation', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const onBuyCoins = jest.fn();
  const render = async (
    overrides: Partial<React.ComponentProps<typeof TopupStep>> = {},
  ) => {
    await act(async () => {
      renderer = TestRenderer.create(
        <TopupStep
          balance={1000}
          busy={false}
          couponApplied={false}
          onBuyCoins={onBuyCoins}
          packages={cappedTerms.sufficientPackages}
          purchasePrice={700}
          rewardContributionLimit={cappedTerms.rewardContributionLimit}
          rewardContributionPercent={cappedTerms.rewardContributionPercent}
          selectedPlan={selectedPlan}
          shortfall={cappedTerms.shortfall}
          sufficientPackage={cappedTerms.sufficientPackage}
          usableCurrentBalance={cappedTerms.usableCurrentBalance}
          {...overrides}
        />,
      );
    });
  };
  const textNodes = () => renderer.root.findAllByType(Text);
  const textValue = (node: TestRenderer.ReactTestInstance) =>
    cleanUnicodeText(React.Children.toArray(node.props.children).join(''));
  const visibleText = () => textNodes().map(textValue);
  const actions = () =>
    renderer.root.findAll(
      node =>
        node.props.accessibilityRole === 'button' &&
        typeof node.props.onPress === 'function',
    );

  beforeEach(() => onBuyCoins.mockClear());
  afterEach(async () => {
    await act(async () => renderer?.unmount());
  });

  it('separates the selected tier total, usable balance, shortfall and total wallet remainder', async () => {
    await render();
    const text = visibleText();
    expect(text).toContain(`الفئة: ${selectedPlan.name}`);
    expect(text).toContain('إجمالي سعر الفئة');
    expect(text).toContain('المتاح لهذا الكورس');
    expect(text).toContain('تحتاج إلى شحن');
    expect(
      renderer.root.findAllByType(CoinAmount).map(node => node.props.value),
    ).toEqual([700, 400, 300, 300, 500]);
    expect(text.join(' ')).toContain(
      `إجمالي رصيد المحفظة ${formatArabicNumber(1000)} عملة`,
    );
    expect(text.join(' ')).toContain(
      `المكافآت تغطي حتى ${formatArabicNumber(300)} عملة`,
    );
    for (const remainder of [600, 800]) {
      expect(text).toContain(
        `يتبقى ${formatArabicNumber(
          remainder,
        )} عملة إجمالًا في المحفظة بعد شراء الكورس`,
      );
    }
    expect(text).not.toContain('اختيار الباقة');
  });

  it('identifies the sufficient package without adding a confirmation or changing the checkout item', async () => {
    await render();
    const button = actions()[0];
    const style = StyleSheet.flatten(button.props.style({pressed: false}));
    expect(style).toMatchObject({
      backgroundColor: Palette.primarySoft,
      borderColor: Palette.primary,
    });
    expect(visibleText().filter(text => text === 'تغطي المبلغ الناقص')).toHaveLength(1);
    expect(button.props.accessibilityState).toEqual({busy: false, disabled: false});
    expect(button.props.accessibilityHint).toContain('ثم يمكنك تأكيد شراء الكورس');
    expect(button.props.accessibilityLabel).toContain(packages[0].label);
    expect(button.props.accessibilityLabel).toContain('تغطي المبلغ الناقص');
    expect(actions()[1].props.accessibilityLabel).not.toContain('تغطي المبلغ الناقص');
    expect(button.props.accessibilityLabel).toContain(
      `يتبقى ${formatArabicNumber(600)} عملة إجمالًا`,
    );
    await act(async () => button.props.onPress());
    expect(onBuyCoins).toHaveBeenCalledTimes(1);
    expect(onBuyCoins).toHaveBeenCalledWith(packages[0]);
  });

  it('uses the current discounted price and avoids duplicating equal total and usable balances', async () => {
    await render({
      balance: 100,
      usableCurrentBalance: 100,
      purchasePrice: 400,
      rewardContributionLimit: 400,
      couponApplied: true,
    });
    expect(visibleText()).toContain('إجمالي السعر بعد الخصم');
    expect(visibleText().join(' ')).not.toContain('إجمالي رصيد المحفظة');
    expect(visibleText()).toContain(
      `يتبقى ${formatArabicNumber(0)} عملة إجمالًا في المحفظة بعد شراء الكورس`,
    );
  });

  it('keeps busy and insufficient packages disabled without claiming the remainder is spendable', async () => {
    const insufficient = {...packages[0], coins: 250};
    await render({packages: [insufficient], sufficientPackage: insufficient});
    expect(actions()[0].props.disabled).toBe(true);
    expect(actions()[0].props.accessibilityState.disabled).toBe(true);
    expect(visibleText()).not.toContain('تغطي المبلغ الناقص');
    expect(visibleText().join(' ')).toContain(
      `تحتاج بعدها ${formatArabicNumber(50)} عملة لإكمال الشراء`,
    );
    expect(visibleText().join(' ')).not.toContain('اشحن بـ');
    await act(async () => renderer.unmount());
    await render({busy: true});
    expect(actions().every(button => button.props.disabled)).toBe(true);
    expect(actions().every(button => button.props.accessibilityState.busy)).toBe(true);
    expect(onBuyCoins).not.toHaveBeenCalled();
  });

  it('handles no recommendation and no sufficient packages without inventing a catalogue outage', async () => {
    await render({sufficientPackage: undefined});
    expect(actions()).toHaveLength(2);
    expect(visibleText()).not.toContain('تغطي المبلغ الناقص');
    await act(async () => renderer.unmount());
    await render({packages: [], sufficientPackage: undefined});
    expect(actions()).toHaveLength(0);
    expect(visibleText()).toContain('لا توجد باقة مناسبة لإكمال الشراء الآن');
  });

  it('allows long provider prices and narrow-screen labels to wrap without fixed height or line truncation', async () => {
    const storePrice = '٩٩٫٠٠ جنيه مصري شامل الضريبة';
    await render({packages: [{...packages[0], displayPrice: storePrice}]});
    const price = textNodes().find(node => textValue(node) === `اشحن بـ ${storePrice}`)!;
    expect(price.props.numberOfLines).toBeUndefined();
    expect(StyleSheet.flatten(price.props.style)).toMatchObject({
      minWidth: 0,
      maxWidth: '100%',
      flexShrink: 1,
    });
    expect(styles.packageAmountRow.flexWrap).toBe('wrap');
    expect(styles.topupMetric.flexWrap).toBe('wrap');
    expect(styles.shortfallRow.flexWrap).toBe('wrap');
    expect(styles.packageCard).toMatchObject({width: '100%', minWidth: 0, padding: 15});
    expect(StyleSheet.flatten(actions()[0].props.style({pressed: false})).height).toBeUndefined();
    expect(actions()[0].props.accessibilityLabel).toContain(storePrice);
  });
});
