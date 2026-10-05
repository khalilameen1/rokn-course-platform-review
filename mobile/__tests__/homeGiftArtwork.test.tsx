import React from 'react';
import {Image, StyleSheet} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {
  AppArtwork,
  ArtworkContext,
  type AppArtworkState,
} from '../src/components/ui/AppArtwork';
import {HomeOverlays} from '../src/screens/home/HomeOverlays';
import type {EngagementMessage} from '../src/services/api/engagement';

jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));

const template = 'https://rokn.test/template.png';
const globalStack = 'https://rokn.test/global.png';
const shippedStack = 'https://rokn.test/assets/app-artwork/v1/coin-stack.png';
const bundledStack = require('../src/assets/images/coins/rokn-coin-stack-3d-alpha.png');
const prompt: EngagementMessage = {
  id: '1',
  key: 'guest_registration_prompt',
  title: 'حصلت على هدية ترحيبية',
  description: '',
  actionLabel: 'سجل الدخول لاستلام الهدية',
  secondaryActionLabel: 'تابع كزائر',
  coins: 60,
  dismissible: true,
  cooldownHours: 0,
  version: '1',
};

describe('Home gift artwork framing and shared source ownership', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  afterEach(() => act(() => renderer?.unmount()));
  const tree = (state: AppArtworkState, uri?: string) => (
    <ArtworkContext.Provider value={state}>
      <HomeOverlays
        campaign={null}
        campaignImageFailed={false}
        onCampaignImageError={jest.fn()}
        onDismissCampaign={jest.fn()}
        guestPrompt={{...prompt, imageUrl: uri}}
        onDismissGuestPrompt={jest.fn()}
        onOpenGuestPrompt={jest.fn()}
      />
    </ArtworkContext.Provider>
  );
  const render = (state: AppArtworkState, uri?: string) =>
    act(() => {
      renderer = TestRenderer.create(tree(state, uri));
    });
  const image = () =>
    renderer.root.findByProps({asset: 'coin_stack'}).findByType(Image);
  const expectFrame = (height: number) => {
    expect(StyleSheet.flatten(image().props.style)).toEqual({
      width: '100%',
      maxWidth: 200,
      height,
    });
    expect(image().props.resizeMode).toBe('contain');
    expect(image().props.resizeMethod).toBe('resize');
    expect(image().props.defaultArtworkStyle).toBeUndefined();
    expect(
      StyleSheet.flatten(
        renderer.root.findByProps({asset: 'coin_stack'}).parent!.props.style,
      ),
    ).toMatchObject({height: 104});
  };
  const fail = () =>
    act(() => image().props.onError({nativeEvent: {error: 'offline'}}));

  it('contains a template upload and global upload, then restores approved offline framing', () => {
    render({urls: {coin_stack: globalStack}}, template);
    expect(image().props.source).toEqual({uri: template});
    expectFrame(104);
    fail();
    expect(image().props.source).toEqual({uri: globalStack});
    expectFrame(104);
    fail();
    expect(image().props.source).toBe(bundledStack);
    expectFrame(200);
  });

  it('preserves the same approved framing for the identified shipped network default', () => {
    render({
      urls: {coin_stack: shippedStack},
      defaults: {coin_stack: shippedStack},
    });
    expect(image().props.source).toEqual({uri: shippedStack});
    expectFrame(200);
    fail();
    expect(image().props.source).toBe(bundledStack);
    expectFrame(200);
  });

  it('does not assign default padding to a template upload or stale default identity', () => {
    render(
      {urls: {coin_stack: shippedStack}, defaults: {coin_stack: shippedStack}},
      template,
    );
    expectFrame(104);
    act(() =>
      renderer.update(
        tree({
          urls: {coin_stack: globalStack},
          defaults: {coin_stack: shippedStack},
        }),
      ),
    );
    expect(image().props.source).toEqual({uri: globalStack});
    expectFrame(104);
  });

  it('recovers when a dashboard URL changes after all previous sources failed', () => {
    render({urls: {coin_stack: globalStack}}, template);
    fail();
    fail();
    expect(image().props.source).toBe(bundledStack);
    const updated = 'https://rokn.test/updated.png';
    act(() => renderer.update(tree({urls: {coin_stack: updated}})));
    expect(image().props.source).toEqual({uri: updated});
    expectFrame(104);
  });

  it('does not change framing for ordinary artwork callers', () => {
    act(() => {
      renderer = TestRenderer.create(
        <ArtworkContext.Provider
          value={{
            urls: {coin_stack: shippedStack},
            defaults: {coin_stack: shippedStack},
          }}>
          <AppArtwork
            asset="coin_stack"
            style={{width: 128, height: 128}}
            resizeMode="contain"
          />
        </ArtworkContext.Provider>,
      );
    });
    expect(
      StyleSheet.flatten(renderer.root.findByType(Image).props.style),
    ).toEqual({width: 128, height: 128});
  });
});
