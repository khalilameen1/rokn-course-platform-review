import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {StyleSheet, type GestureResponderEvent} from 'react-native';
import {VideoChrome} from '../src/components/VideoPlayer/video/VideoChrome';
import {useVideoTimelineController} from '../src/components/VideoPlayer/video/useVideoTimelineController';

jest.mock('react-native-linear-gradient', () => 'LinearGradient');

const touch = (
  x: number,
  previousX: number,
  locationX: number,
  stamp: number,
) =>
  ({
    nativeEvent: {locationX},
    touchHistory: {
      numberActiveTouches: 1,
      indexOfSingleActiveTouch: 0,
      mostRecentTimeStamp: stamp,
      touchBank: [
        {
          touchActive: true,
          currentPageX: x,
          currentPageY: 700,
          previousPageX: previousX,
          previousPageY: 700,
          currentTimeStamp: stamp,
        },
      ],
    },
  } as unknown as GestureResponderEvent);

async function mount(duration = 100) {
  const seek = jest.fn();
  const videoRef = {current: {seek}} as never;
  let current!: ReturnType<typeof useVideoTimelineController>;
  function Harness() {
    current = useVideoTimelineController({
      initialDuration: duration,
      initialPosition: 0,
      videoRef,
    });
    return null;
  }
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(() => {
    renderer = TestRenderer.create(<Harness />);
  });
  await act(() => current.setTrackWidth(200));
  return {
    get current() {
      return current;
    },
    renderer,
    seek,
  };
}

describe('video scrub gesture ownership', () => {
  it('retains a claimed scrub when the parent pager requests the responder', async () => {
    const h = await mount();
    const handlers = h.current.panHandlers;
    await act(() => handlers.onResponderGrant?.(touch(20, 20, 20, 1)));
    expect(handlers.onResponderTerminationRequest?.(touch(20, 20, 20, 1))).toBe(
      false,
    );
    await act(() => handlers.onResponderRelease?.(touch(20, 20, 20, 1)));
    expect(h.seek).toHaveBeenCalledWith(10);
    await act(() => h.renderer.unmount());
  });

  it('uses the drag distance instead of changing child-relative coordinates', async () => {
    const h = await mount();
    const handlers = h.current.panHandlers;
    await act(() => handlers.onResponderGrant?.(touch(20, 20, 20, 1)));
    await act(() => handlers.onResponderMove?.(touch(120, 20, 5, 2)));
    expect(h.current.previewTime).toBe(60);
    expect(h.seek).not.toHaveBeenCalled();
    await act(() => handlers.onResponderRelease?.(touch(120, 120, 5, 3)));
    expect(h.seek).toHaveBeenCalledTimes(1);
    expect(h.seek).toHaveBeenCalledWith(60);
    expect(h.current.previewTime).toBeNull();
    await act(() => h.renderer.unmount());
  });

  it('keeps the visual timeline on the same physical left-to-right axis as touch coordinates', async () => {
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(() => {
      renderer = TestRenderer.create(
        <VideoChrome
          bottomInset={0}
          currentTime={60}
          failureKind="source"
          isBuffering={false}
          isLoaded
          onRetry={jest.fn()}
          onSeekBy={jest.fn()}
          onTogglePaused={jest.fn()}
          onTrackWidth={jest.fn()}
          panHandlers={{}}
          pausedByUser={false}
          previewTime={60}
          recoveryMessage=""
          sourceFailed={false}
          timeline={{
            accessibilityDuration: 100,
            accessibilityPosition: 60,
            bufferedProgress: 0.8,
            displayedTime: 60,
            duration: 100,
            progress: 0.6,
            remaining: 40,
          }}
          trackWidth={200}
          unsupportedSource={false}
        />,
      );
    });

    const touchTrack = StyleSheet.flatten(
      renderer.root.findByProps({testID: 'video-timeline-touch-track'}).props
        .style,
    );
    const played = StyleSheet.flatten(
      renderer.root.findByProps({testID: 'video-timeline-played'}).props.style,
    );
    const scrubber = StyleSheet.flatten(
      renderer.root.findByProps({testID: 'video-timeline-scrubber'}).props.style,
    );

    expect(touchTrack.direction).toBe('ltr');
    expect(played.start).toBe(0);
    expect(played.width).toBe('60%');
    expect(scrubber.start).toBe(114);
    expect(played.left).toBeUndefined();
    expect(scrubber.left).toBeUndefined();

    await act(() => renderer.unmount());
  });

  it('does not capture paging touches before the duration is available', async () => {
    const h = await mount(0);
    expect(
      h.current.panHandlers.onStartShouldSetResponder?.(touch(20, 20, 20, 1)),
    ).toBe(false);
    await act(() => h.renderer.unmount());
  });
});
