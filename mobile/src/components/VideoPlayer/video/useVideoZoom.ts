import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {Animated} from 'react-native';
import {Gesture} from 'react-native-gesture-handler';

export const MAX_VIDEO_ZOOM = 3;
const ZOOM_SNAP_BACK = 1.05;

type ZoomState = {
  scale: number;
  x: number;
  y: number;
  startScale: number;
  startX: number;
  startY: number;
};

const clamp = (value: number, min: number, max: number) =>
  Math.min(max, Math.max(min, value));

/** Keeps the zoomed picture covering the frame: no empty edge while panning. */
export const clampVideoPan = (
  scale: number,
  offset: number,
  extent: number,
) => {
  const limit = Math.max(0, (extent * (scale - 1)) / 2);
  return clamp(offset, -limit, limit);
};

/**
 * Pinch-to-zoom with two-finger pan on the lesson video, the convention of
 * TikTok's in-feed zoom and YouTube's pinch-to-zoom: it lets a learner read a
 * small software UI (Blender panels, menus) inside a portrait reel without
 * changing the content. One-finger gestures keep their meaning: tap pauses,
 * vertical swipes page the feed. Zoom resets when the reel stops being active.
 */
export const useVideoZoom = ({
  active,
  height,
  width,
}: {
  active: boolean;
  height: number;
  width: number;
}) => {
  const scale = useRef(new Animated.Value(1)).current;
  const translateX = useRef(new Animated.Value(0)).current;
  const translateY = useRef(new Animated.Value(0)).current;
  const state = useRef<ZoomState>({
    scale: 1,
    x: 0,
    y: 0,
    startScale: 1,
    startX: 0,
    startY: 0,
  });
  const size = useRef({width, height});
  size.current = {width, height};
  const [zoomed, setZoomed] = useState(false);

  const apply = useCallback(() => {
    const current = state.current;
    scale.setValue(current.scale);
    translateX.setValue(current.x);
    translateY.setValue(current.y);
  }, [scale, translateX, translateY]);

  const reset = useCallback(() => {
    Object.assign(state.current, {scale: 1, x: 0, y: 0});
    apply();
    setZoomed(false);
  }, [apply]);

  useEffect(() => {
    if (!active) reset();
  }, [active, reset]);

  const gesture = useMemo(() => {
    const pinch = Gesture.Pinch()
      .runOnJS(true)
      .onStart(() => {
        const current = state.current;
        current.startScale = current.scale;
        current.startX = current.x;
        current.startY = current.y;
      })
      .onUpdate(event => {
        const current = state.current;
        const {width: frameWidth, height: frameHeight} = size.current;
        const nextScale = clamp(
          current.startScale * event.scale,
          1,
          MAX_VIDEO_ZOOM,
        );
        // Keep the pinch focal point under the fingers while scaling.
        const ratio = nextScale / current.startScale;
        const focalX = event.focalX - frameWidth / 2;
        const focalY = event.focalY - frameHeight / 2;
        current.scale = nextScale;
        current.x = clampVideoPan(
          nextScale,
          focalX - (focalX - current.startX) * ratio,
          frameWidth,
        );
        current.y = clampVideoPan(
          nextScale,
          focalY - (focalY - current.startY) * ratio,
          frameHeight,
        );
        apply();
      })
      .onEnd(() => {
        if (state.current.scale < ZOOM_SNAP_BACK) reset();
        else setZoomed(true);
      });
    const pan = Gesture.Pan()
      .minPointers(2)
      .runOnJS(true)
      .onStart(() => {
        state.current.startX = state.current.x;
        state.current.startY = state.current.y;
      })
      .onUpdate(event => {
        const current = state.current;
        if (current.scale <= 1) return;
        current.x = clampVideoPan(
          current.scale,
          current.startX + event.translationX,
          size.current.width,
        );
        current.y = clampVideoPan(
          current.scale,
          current.startY + event.translationY,
          size.current.height,
        );
        apply();
      });
    return Gesture.Simultaneous(pinch, pan);
  }, [apply, reset]);

  const style = useMemo(
    () => ({transform: [{translateX}, {translateY}, {scale}]}),
    [scale, translateX, translateY],
  );

  return {gesture, reset, style, zoomed};
};
