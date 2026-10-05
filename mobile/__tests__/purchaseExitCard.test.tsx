import React from 'react';
import {Image, Modal, ScrollView, StyleSheet, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 12, bottom: 8, left: 0, right: 0}),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
import {ArtworkContext} from '../src/components/ui/AppArtwork';
import {CourseRetentionDialog} from '../src/screens/CourseDetails/details/CourseRetentionDialog';

it('uses approved copy, dashboard coin-stack with safe fallback, centered scrollable card and wallet action', () => {
  const actions: string[] = [];
  const uploaded = 'https://rokn.test/custom-stack.png';
  let renderer!: TestRenderer.ReactTestRenderer;
  act(() => {
    renderer = TestRenderer.create(
      <ArtworkContext.Provider value={{urls: {coin_stack: uploaded}}}>
        <CourseRetentionDialog
          bottomInset={8}
          isTablet={false}
          owned={false}
          retentionVisible
          onClose={() => actions.push('close')}
          onOpenWallet={() => actions.push('wallet')}
        />
      </ArtworkContext.Provider>,
    );
  });
  try {
    const strings = renderer.root
      .findAllByType(Text)
      .map(node => node.props.children);
    expect(strings).toContain('اكسب عملات مجانية');
    expect(strings).toContain('تستخدمها في شراء الكورسات');
    expect(strings).toContain('اكتشف المهام');
    expect(strings.join(' ')).not.toMatch(/تخفيض|خفّض|خصم|الكورس مجاني/);
    expect(
      StyleSheet.flatten(
        renderer.root.findByProps({testID: 'purchase-exit-card'}).props.style,
      ),
    ).toMatchObject({
      width: '100%',
      maxWidth: 400,
      minHeight: 320,
      borderRadius: 24,
    });
    expect(
      StyleSheet.flatten(
        renderer.root.findByType(ScrollView).props.contentContainerStyle,
      ),
    ).toMatchObject({
      flexGrow: 1,
      justifyContent: 'center',
      paddingTop: 28,
      paddingBottom: 24,
    });
    const artwork = renderer.root.findByProps({asset: 'coin_stack'});
    const image = artwork.findByType(Image);
    expect(image.props.source).toEqual({uri: uploaded});
    expect(StyleSheet.flatten(image.props.style)).toMatchObject({height: 104});
    expect(image.props.resizeMode).toBe('contain');
    act(() => image.props.onError({nativeEvent: {error: 'offline'}}));
    expect(
      StyleSheet.flatten(artwork.findByType(Image).props.style),
    ).toMatchObject({height: 200});
    const action = renderer.root
      .findAllByProps({accessibilityRole: 'button'})
      .find(node =>
        node
          .findAllByType(Text)
          .some(text => text.props.children === 'اكتشف المهام'),
      )!;
    act(() => action.props.onPress());
    expect(actions).toEqual(['close', 'wallet']);
    act(() => renderer.root.findByType(Modal).props.onRequestClose());
    expect(actions).toEqual(['close', 'wallet', 'close']);
  } finally {
    act(() => renderer.unmount());
  }
});
