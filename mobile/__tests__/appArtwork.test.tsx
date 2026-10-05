import React from 'react';
import {Image, StyleSheet} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {AppArtwork, levelArtworkKey} from '../src/components/ui/AppArtwork';
import {AppArtworkProvider} from '../src/components/AppArtworkProvider';
import {getPublicAppSettings} from '../src/services/publicAppSettings';

let mockActive = true;
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => mockActive,
}));
jest.mock('../src/services/publicAppSettings', () => ({
  getPublicAppSettings: jest.fn(),
}));
const settings = getPublicAppSettings as jest.Mock;

describe('Dashboard artwork delivery', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  beforeEach(() => {
    mockActive = true;
    settings.mockReset();
  });
  afterEach(() => {
    act(() => renderer?.unmount());
  });
  const render = async (uri?: string) => {
    await act(async () => {
      renderer = TestRenderer.create(
        <AppArtworkProvider>
          <AppArtwork asset="badge_senior" uri={uri} />
          <AppArtwork asset="coin" />
        </AppArtworkProvider>,
      );
    });
  };
  it('uses one shared settings request and prefers a level upload over the default', async () => {
    settings.mockResolvedValue({
      artwork: {
        coin: 'https://rokn.test/coin.png',
        badge_senior: 'https://rokn.test/senior.png',
      },
    });
    await render('https://rokn.test/custom.png');
    expect(settings).toHaveBeenCalledTimes(1);
    const images = renderer.root.findAllByType(Image);
    expect(images.map(item => item.props.source)).toEqual([
      {uri: 'https://rokn.test/custom.png'},
      {uri: 'https://rokn.test/coin.png'},
    ]);
    act(() => images[0].props.onError({nativeEvent: {error: 'unavailable'}}));
    expect(renderer.root.findAllByType(Image)[0].props.source).toEqual({
      uri: 'https://rokn.test/senior.png',
    });
    act(() =>
      renderer.root
        .findAllByType(Image)[0]
        .props.onError({nativeEvent: {error: 'offline'}}),
    );
    expect(renderer.root.findAllByType(Image)[0].props.source).toBe(
      require('../src/assets/images/badges/senior-printed.png'),
    );
  });
  it('keeps the approved offline images when settings cannot load', async () => {
    settings.mockRejectedValue(new Error('offline'));
    await render();
    expect(renderer.root.findAllByType(Image)[1].props.source).toBe(
      require('../src/assets/images/coins/rokn-coin-minted.png'),
    );
  });
  it('refreshes on foreground and recovers after a failed old URL', async () => {
    settings.mockResolvedValue({artwork: {coin: 'https://rokn.test/old.png'}});
    await render();
    act(() =>
      renderer.root
        .findAllByType(Image)[1]
        .props.onError({nativeEvent: {error: 'missing'}}),
    );
    mockActive = false;
    await act(async () =>
      renderer.update(
        <AppArtworkProvider>
          <AppArtwork asset="coin" />
        </AppArtworkProvider>,
      ),
    );
    settings.mockResolvedValue({artwork: {coin: 'https://rokn.test/new.png'}});
    mockActive = true;
    await act(async () =>
      renderer.update(
        <AppArtworkProvider>
          <AppArtwork asset="coin" />
        </AppArtworkProvider>,
      ),
    );
    expect(settings).toHaveBeenCalledTimes(2);
    expect(renderer.root.findByType(Image).props.source).toEqual({
      uri: 'https://rokn.test/new.png',
    });
  });
  it('chooses the rank from data rather than translated titles', () => {
    expect([1, 2, 3].map(levelArtworkKey)).toEqual([
      'badge_junior',
      'badge_mid',
      'badge_senior',
    ]);
  });
  it('delivers source identity and URL together, retiring default framing on a dashboard upload', async () => {
    const shipped = 'https://rokn.test/coin-stack.png';
    const uploaded = 'https://rokn.test/uploaded.png';
    const tree = () => (
      <AppArtworkProvider>
        <AppArtwork
          asset="coin_stack"
          style={{width: 200, height: 104}}
          defaultArtworkStyle={{height: 200}}
          resizeMode="contain"
        />
      </AppArtworkProvider>
    );
    settings.mockResolvedValue({
      artwork: {coin_stack: shipped},
      artwork_defaults: {coin_stack: shipped},
    });
    await act(async () => {
      renderer = TestRenderer.create(tree());
    });
    expect(
      StyleSheet.flatten(renderer.root.findByType(Image).props.style).height,
    ).toBe(200);
    mockActive = false;
    await act(async () => renderer.update(tree()));
    settings.mockResolvedValue({
      artwork: {coin_stack: uploaded},
      artwork_defaults: {},
    });
    mockActive = true;
    await act(async () => renderer.update(tree()));
    expect(renderer.root.findByType(Image).props.source).toEqual({
      uri: uploaded,
    });
    expect(
      StyleSheet.flatten(renderer.root.findByType(Image).props.style).height,
    ).toBe(104);
  });
});
