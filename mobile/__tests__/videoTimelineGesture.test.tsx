import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import type {GestureResponderEvent} from 'react-native';
import {useVideoTimelineController} from '../src/components/VideoPlayer/video/useVideoTimelineController';

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

  it('does not capture paging touches before the duration is available', async () => {
    const h = await mount(0);
    expect(
      h.current.panHandlers.onStartShouldSetResponder?.(touch(20, 20, 20, 1)),
    ).toBe(false);
    await act(() => h.renderer.unmount());
  });
});
