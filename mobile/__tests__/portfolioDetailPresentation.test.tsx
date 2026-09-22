import React from 'react';
import {ActivityIndicator, StyleSheet, View} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({navigate: jest.fn()}),
}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('react-native-video', () => 'Video');
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: jest.fn(),
}));
jest.mock('../src/components/touchables/Button', () => () => null);
jest.mock('../src/components/ui/PremiumUI', () => ({
  MetaPill: () => null,
  SectionHeading: () => null,
  StatusView: () => null,
}));
jest.mock('../src/screens/Profile/gallery/PortfolioProjectGrid', () => ({
  PortfolioProjectGrid: () => null,
}));

import {Palette} from '../src/constants/designSystem';
import {PortfolioDetailActions} from '../src/screens/Profile/gallery/PortfolioGalleryView';

type ActionsController = React.ComponentProps<
  typeof PortfolioDetailActions
>['controller'];

const makeController = (
  overrides: Partial<ActionsController> = {},
): ActionsController => ({
  addSelectedMedia: jest.fn(),
  beginEdit: jest.fn(),
  closeProject: jest.fn(),
  confirmDeleteSelectedProject: jest.fn(),
  finalizeSelectedProject: jest.fn(),
  onSharePortfolio: jest.fn(),
  saving: false,
  selectedAction: 'complete',
  selectedMediaSlots: 4,
  ...overrides,
});

describe('portfolio detail action presentation', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;

  afterEach(() => {
    act(() => renderer?.unmount());
    renderer = undefined;
  });

  const mount = (controller = makeController(), fontScale = 1) => {
    act(() => {
      renderer = TestRenderer.create(
        <PortfolioDetailActions
          controller={controller}
          fontScale={fontScale}
        />,
      );
    });
    const actions = () =>
      renderer!.root.findAll(
        node =>
          node.props.accessibilityRole === 'button' &&
          typeof node.props.style === 'function',
      );
    const action = (label: string) =>
      actions().find(node => node.props.accessibilityLabel === label)!;
    return {controller, actions, action};
  };
  const actionStyle = (node: TestRenderer.ReactTestInstance) =>
    StyleSheet.flatten(node.props.style({pressed: false}));

  it.each([
    {state: 'complete' as const, label: 'إتمام المشروع'},
    {state: 'share' as const, label: 'مشاركة البورتفوليو'},
  ])('emphasizes only the existing $state action', ({state, label}) => {
    const view = mount(makeController({selectedAction: state}));
    const primary = view
      .actions()
      .filter(node => actionStyle(node).backgroundColor === Palette.primary);
    expect(primary).toHaveLength(1);
    expect(primary[0].props.accessibilityLabel).toBe(label);
    expect(view.actions()[0]).toBe(primary[0]);
    expect(
      view.action(
        state === 'complete' ? 'مشاركة البورتفوليو' : 'إتمام المشروع',
      ),
    ).toBeUndefined();

    act(() => primary[0].props.onPress());
    expect(
      state === 'complete'
        ? view.controller.finalizeSelectedProject
        : view.controller.onSharePortfolio,
    ).toHaveBeenCalledTimes(1);
  });

  it.each([
    {selectedAction: 'upload' as const},
    {selectedAction: null},
    {selectedAction: 'share' as const, onSharePortfolio: undefined},
  ])('does not invent a completion or sharing action for %o', overrides => {
    const view = mount(makeController(overrides));
    expect(view.action('إتمام المشروع')).toBeUndefined();
    expect(view.action('مشاركة البورتفوليو')).toBeUndefined();
    expect(view.actions()).toHaveLength(4);
  });

  it('keeps media, edit, confirmed deletion and close on their original callbacks', () => {
    const view = mount();
    act(() => {
      view.action('إضافة صور أو فيديو').props.onPress();
      view.action('تعديل المشروع').props.onPress();
      view.action('حذف المشروع').props.onPress();
      view.action('إغلاق تفاصيل المشروع').props.onPress();
    });
    expect(view.controller.addSelectedMedia).toHaveBeenCalledTimes(1);
    expect(view.controller.beginEdit).toHaveBeenCalledTimes(1);
    expect(view.controller.confirmDeleteSelectedProject).toHaveBeenCalledTimes(
      1,
    );
    expect(view.controller.closeProject).toHaveBeenCalledTimes(1);
    expect(view.controller.finalizeSelectedProject).not.toHaveBeenCalled();
    expect(view.controller.onSharePortfolio).not.toHaveBeenCalled();
  });

  it.each(['complete', 'share'] as const)(
    'preserves busy locks and loaders for %s without disabling close',
    selectedAction => {
      const view = mount(makeController({selectedAction, saving: true}));
      for (const label of [
        selectedAction === 'complete' ? 'إتمام المشروع' : 'مشاركة البورتفوليو',
        'إضافة صور أو فيديو',
        'تعديل المشروع',
        'حذف المشروع',
      ]) {
        expect(view.action(label).props.disabled).toBe(true);
        expect(view.action(label).props.accessibilityState.disabled).toBe(true);
      }
      for (const label of ['إضافة صور أو فيديو', 'حذف المشروع']) {
        expect(view.action(label).props.accessibilityState.busy).toBe(true);
        expect(
          view.action(label).findAllByType(ActivityIndicator),
        ).toHaveLength(1);
      }
      expect(view.action('إغلاق تفاصيل المشروع').props.disabled).not.toBe(true);
    },
  );

  it('disables only adding media when no media slots remain', () => {
    const view = mount(makeController({selectedMediaSlots: 0}));
    expect(view.action('إضافة صور أو فيديو').props.disabled).toBe(true);
    expect(view.action('إضافة صور أو فيديو').props.accessibilityState).toEqual({
      busy: false,
      disabled: true,
    });
    for (const label of ['إتمام المشروع', 'تعديل المشروع', 'حذف المشروع']) {
      expect(view.action(label).props.disabled).toBe(false);
    }
  });

  it.each([1, 1.3, 2])(
    'keeps flat 48dp targets and wrapping RTL rows at font scale %s',
    fontScale => {
      const view = mount(makeController(), fontScale);
      for (const button of view.actions()) {
        const style = actionStyle(button);
        expect(style.minHeight).toBeGreaterThanOrEqual(48);
        expect(style.height).toBeUndefined();
        expect(style.shadowOpacity).toBeUndefined();
        expect(style.elevation).toBeUndefined();
      }
      const media = view.action('إضافة صور أو فيديو');
      const edit = view.action('تعديل المشروع');
      expect(actionStyle(media).flexBasis).toBe(
        fontScale >= 1.3 ? '100%' : 140,
      );
      expect(actionStyle(edit).flexBasis).toBe(fontScale >= 1.3 ? '100%' : 140);
      expect(actionStyle(media).maxWidth).toBe('100%');

      const wrappingRows = renderer!.root
        .findAllByType(View)
        .map(node => StyleSheet.flatten(node.props.style))
        .filter(style => style?.flexWrap === 'wrap');
      expect(wrappingRows).toHaveLength(2);
      wrappingRows.forEach(style => {
        expect(style.direction).toBe('rtl');
        expect(style.flexDirection).toBe('row');
      });
      expect(actionStyle(view.action('حذف المشروع')).backgroundColor).toBe(
        undefined,
      );
      expect(
        actionStyle(view.action('إغلاق تفاصيل المشروع')).backgroundColor,
      ).toBe(undefined);
    },
  );
});
