import React from 'react';
import {StyleSheet, Text} from 'react-native';
import ReactTestRenderer from 'react-test-renderer';

jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppActiveState: () => true,
  useAppForegroundState: () => true,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('react-native-video', () => {
  const ReactModule = require('react');
  const ReactNative = require('react-native');
  return {
    __esModule: true,
    default: ReactModule.forwardRef((props: object, _ref: unknown) =>
      ReactModule.createElement(ReactNative.View, {
        ...props,
        testID: 'native-video',
      }),
    ),
    BufferingStrategyType: {DEPENDING_ON_MEMORY: 'DependingOnMemory'},
    SelectedVideoTrackType: {AUTO: 'auto', RESOLUTION: 'resolution'},
    ViewType: {TEXTURE: 'texture'},
  };
});
jest.mock('react-native-linear-gradient', () => 'LinearGradient');

import VideoComponent from '../src/components/VideoPlayer/VideoComponent';
import FeedActions from '../src/components/VideoPlayer/feedSideBar/FeedActions';
import type {CourseReel} from '../src/components/VideoPlayer/types';
import {
  parseCaptionTracks,
  selectCaptionTrack,
  setCaptionsEnabled,
} from '../src/components/VideoPlayer/video/captions';
import {
  formatRemainingTime,
  resolveVideoPresentation,
} from '../src/components/VideoPlayer/video/policy';
import {clampVideoPan} from '../src/components/VideoPlayer/video/useVideoZoom';
import {Type} from '../src/constants/designSystem';

const reel: CourseReel = {
  id: 'reel-1',
  lessonId: 'lesson-1',
  sectionId: 'section-1',
  moduleId: 'module-1',
  title: 'مقطع',
  caption: '',
  videoUrl: 'https://cdn.example.com/lesson.m3u8',
  availableQualities: ['auto'],
  durationSeconds: 90,
  isPreview: false,
  isLocked: false,
  isCompleted: false,
  reelNumber: 1,
};

const loadEvent = (width: number, height: number, textTracks = []) => ({
  currentTime: 0,
  duration: 90,
  naturalSize: {
    width,
    height,
    orientation: width > height ? 'landscape' : 'portrait',
  },
  audioTracks: [],
  textTracks,
  videoTracks: [],
});

const nativeVideo = (renderer: ReactTestRenderer.ReactTestRenderer) =>
  renderer.root.findByProps({testID: 'native-video'});

afterEach(() => setCaptionsEnabled(false));

describe('remaining time label', () => {
  it('keeps the minus sign before an LTR-isolated clock in Arabic UI', () => {
    expect(formatRemainingTime(30)).toBe('⁦−٠:٣٠⁩');
    expect(formatRemainingTime(605)).toBe('⁦−١٠:٠٥⁩');
  });

  it('renders the isolated clock at the design-system caption size', async () => {
    let renderer!: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <VideoComponent data={reel} width={390} height={844} isVisible />,
      );
    });
    const label = renderer.root
      .findAllByType(Text)
      .find(node => node.props.children === formatRemainingTime(90));
    expect(label).toBeDefined();
    expect(StyleSheet.flatten(label!.props.style).fontSize).toBe(
      Type.caption.fontSize,
    );
    expect(label!.props.accessibilityLabel).toBe('المتبقي ١:٣٠');
    await ReactTestRenderer.act(() => renderer.unmount());
  });
});

describe('landscape lessons in the portrait stage', () => {
  it('letterboxes landscape sources and reports the picture band', () => {
    expect(
      resolveVideoPresentation({
        width: 390,
        height: 844,
        naturalWidth: 1080,
        naturalHeight: 1920,
      }),
    ).toEqual({resizeMode: 'cover', contentFrame: null});
    const landscape = resolveVideoPresentation({
      width: 390,
      height: 844,
      naturalWidth: 1920,
      naturalHeight: 1080,
      naturalOrientation: 'landscape',
    });
    expect(landscape.resizeMode).toBe('contain');
    expect(landscape.contentFrame!.top).toBeCloseTo((844 - 219.375) / 2);
    expect(landscape.contentFrame!.bottom).toBeCloseTo((844 + 219.375) / 2);
  });

  it('switches the player to contain and resets that state for the next reel', async () => {
    const onContentFrameChange = jest.fn();
    let renderer!: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <VideoComponent
          data={reel}
          width={390}
          height={844}
          isVisible
          onContentFrameChange={onContentFrameChange}
        />,
      );
    });
    expect(nativeVideo(renderer).props.resizeMode).toBe('cover');
    await ReactTestRenderer.act(() =>
      nativeVideo(renderer).props.onLoad(loadEvent(1920, 1080)),
    );
    expect(nativeVideo(renderer).props.resizeMode).toBe('contain');
    expect(onContentFrameChange).toHaveBeenLastCalledWith({
      top: expect.any(Number),
      bottom: expect.any(Number),
    });

    // A recycled row receiving another reel remounts the keyed player.
    await ReactTestRenderer.act(() =>
      renderer.update(
        <VideoComponent
          data={{...reel, id: 'reel-2', lessonId: 'lesson-2'}}
          width={390}
          height={844}
          isVisible
          onContentFrameChange={onContentFrameChange}
        />,
      ),
    );
    expect(nativeVideo(renderer).props.resizeMode).toBe('cover');
    expect(onContentFrameChange).toHaveBeenLastCalledWith(null);
    await ReactTestRenderer.act(() => renderer.unmount());
  });

  it('keeps a zoomed picture covering the frame while panning', () => {
    expect(clampVideoPan(1, 40, 390)).toBe(0);
    expect(clampVideoPan(2, 500, 390)).toBe(195);
    expect(clampVideoPan(2, -500, 390)).toBe(-195);
    expect(clampVideoPan(1.5, 20, 390)).toBe(20);
  });
});

describe('captions', () => {
  it('accepts only HTTPS WebVTT manifest tracks with a language', () => {
    expect(
      parseCaptionTracks({
        caption_tracks: [
          {url: 'https://vz.example/captions/ar.vtt', language: 'AR'},
          {url: 'https://vz.example/captions/ar2.vtt', language: 'ar'},
          {url: 'http://vz.example/captions/en.vtt', language: 'en'},
          {url: 'https://vz.example/captions/en.vtt', language: 'english'},
          {
            url: 'https://vz.example/chapters.vtt',
            language: 'en',
            kind: 'chapters',
          },
        ],
      }),
    ).toEqual([
      {
        url: 'https://vz.example/captions/ar.vtt',
        language: 'ar',
        label: 'العربية',
      },
    ]);
    expect(parseCaptionTracks({})).toEqual([]);
  });

  it('selects nothing without tracks, explicit off, and Arabic first', () => {
    expect(selectCaptionTrack({enabled: true, reported: []})).toBeUndefined();
    const reported = [
      {index: 0, language: 'en'},
      {index: 1, language: 'ar-eg'},
    ];
    expect(selectCaptionTrack({enabled: false, reported})).toEqual({
      type: 'disabled',
    });
    expect(selectCaptionTrack({enabled: true, reported})).toEqual({
      type: 'index',
      value: 1,
    });
  });

  it('shows CC only when tracks exist and drives the native text track', async () => {
    let renderer!: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <VideoComponent data={reel} width={390} height={844} isVisible />,
      );
    });
    expect(
      renderer.root.findAllByProps({testID: 'video-captions-toggle'}),
    ).toHaveLength(0);
    expect(nativeVideo(renderer).props.selectedTextTrack).toBeUndefined();

    await ReactTestRenderer.act(() =>
      renderer.update(
        <VideoComponent
          data={{
            ...reel,
            captionTracks: [
              {
                url: 'https://vz.example/ar.vtt',
                language: 'ar',
                label: 'العربية',
              },
            ],
          }}
          width={390}
          height={844}
          isVisible
        />,
      ),
    );
    expect(nativeVideo(renderer).props.source.textTracks).toEqual([
      {
        title: 'العربية',
        language: 'ar',
        type: 'text/vtt',
        uri: 'https://vz.example/ar.vtt',
      },
    ]);
    expect(nativeVideo(renderer).props.selectedTextTrack).toEqual({
      type: 'disabled',
    });
    const toggle = renderer.root.findByProps({testID: 'video-captions-toggle'});
    expect(toggle.props.accessibilityState).toEqual({checked: false});
    await ReactTestRenderer.act(() => toggle.props.onPress());
    expect(nativeVideo(renderer).props.selectedTextTrack).toEqual({
      type: 'language',
      value: 'ar',
    });

    // Tracks reported by the native player (HLS subtitle renditions) win.
    await ReactTestRenderer.act(() =>
      nativeVideo(renderer).props.onTextTracks({
        textTracks: [{index: 2, language: 'ar'}],
      }),
    );
    expect(nativeVideo(renderer).props.selectedTextTrack).toEqual({
      type: 'index',
      value: 2,
    });
    await ReactTestRenderer.act(() => renderer.unmount());
  });
});

describe('feed action rail', () => {
  const renderRail = (contentBottom?: number) =>
    ReactTestRenderer.create(
      <FeedActions
        bottomInset={0}
        compact={false}
        contentBottom={contentBottom}
        currentReelNumber={1}
        isSaved={false}
        savePending={false}
        totalReels={15}
        onOpenChat={jest.fn()}
        onOpenIndex={jest.fn()}
        onPressSave={jest.fn()}
        onLongPressSave={jest.fn()}
      />,
    );

  it('uses shadowed labels without a second plate behind the text', async () => {
    let renderer!: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(() => {
      renderer = renderRail();
    });
    const label = renderer.root
      .findAllByType(Text)
      .find(node => node.props.children === 'اسأل');
    const style = StyleSheet.flatten(label!.props.style);
    expect(style.backgroundColor).toBeUndefined();
    expect(style.textShadowColor).toBeDefined();
    expect(style.fontSize).toBe(Type.caption.fontSize);
  });

  it('tightens into the letterbox when it would cover a picture band', async () => {
    let renderer!: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(() => {
      renderer = renderRail(531);
    });
    const container = () => renderer.root.findAllByType('View' as never)[0];
    const gap = () => StyleSheet.flatten(container().props.style).gap;
    expect(gap()).toBe(17);
    await ReactTestRenderer.act(() =>
      container().props.onLayout({
        nativeEvent: {layout: {y: 513, height: 241}},
      }),
    );
    expect(gap()).toBe(10);
    // Measuring the compact rail again must not flip it back.
    await ReactTestRenderer.act(() =>
      container().props.onLayout({
        nativeEvent: {layout: {y: 527, height: 227}},
      }),
    );
    expect(gap()).toBe(10);
  });
});
