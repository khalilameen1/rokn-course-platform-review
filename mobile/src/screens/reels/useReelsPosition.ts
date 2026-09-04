import {useCallback, useEffect, useRef, useState} from 'react';
import type {MutableRefObject} from 'react';
import type {FlatList, LayoutChangeEvent, ViewToken} from 'react-native';
import {flushPendingPlaybackPositions} from '../../components/VideoPlayer/courseLearningApi';
import {selectPrimaryViewableItem} from '../../components/VideoPlayer/courseLearning/viewability';
import type {CourseFeedItem} from '../../components/VideoPlayer/types';

type Params = {
  feedItems: CourseFeedItem[];
  ownerGeneration: MutableRefObject<number>;
  scopeKey: string;
};

export const resolvePendingFeedPosition = (
  feedItems: Pick<CourseFeedItem, 'key'>[],
  request: {key: string | null; index: number | null},
): number | null => {
  if (request.key) {
    const target = feedItems.findIndex(item => item.key === request.key);
    return target >= 0 ? target : null;
  }
  if (
    request.index === null ||
    !Number.isFinite(request.index) ||
    request.index < 0 ||
    request.index >= feedItems.length
  ) {
    return null;
  }
  return Math.floor(request.index);
};

/**
 * Owns the logical feed position and synchronises the native list to it.
 * Programmatic scrolls only request movement; viewability is the authority
 * that commits an ordinary swipe, while route anchors commit atomically with
 * their matching native offset.
 */
export const useReelsPosition = ({
  feedItems,
  ownerGeneration,
  scopeKey,
}: Params) => {
  const listRef = useRef<FlatList<CourseFeedItem>>(null);
  const pendingInitialKey = useRef<string | null>(null);
  const pendingInitialIndex = useRef<number | null>(null);
  const currentIndexRef = useRef(0);
  const feedLengthRef = useRef(0);
  const frameHeightRef = useRef(0);
  const scrollOffsetRef = useRef(0);
  const scrollDirectionRef = useRef<1 | -1>(1);
  const [currentIndex, setCurrentIndex] = useState(0);
  const [layout, setLayout] = useState({width: 0, height: 0});
  const [paging, setPaging] = useState(false);
  const [initialRequestRevision, setInitialRequestRevision] = useState(0);

  frameHeightRef.current = layout.height;
  feedLengthRef.current = feedItems.length;

  const requestInitialPosition = useCallback(
    (request: {key?: string; index?: number}) => {
      pendingInitialKey.current = request.key || null;
      pendingInitialIndex.current =
        typeof request.index === 'number' ? request.index : null;
      setInitialRequestRevision(value => value + 1);
    },
    [],
  );

  useEffect(() => {
    pendingInitialKey.current = null;
    pendingInitialIndex.current = null;
    currentIndexRef.current = 0;
    scrollOffsetRef.current = 0;
    scrollDirectionRef.current = 1;
    setCurrentIndex(0);
    setPaging(false);
  }, [scopeKey]);

  useEffect(() => {
    if (!feedItems.length || !layout.height) return;
    if (!pendingInitialKey.current && pendingInitialIndex.current === null)
      return;
    const target = resolvePendingFeedPosition(feedItems, {
      key: pendingInitialKey.current,
      index: pendingInitialIndex.current,
    });
    // Course progression can request the next authored step immediately
    // before the refreshed learning map reaches this hook. Keep that target
    // pending until the matching feed revision arrives; consuming it here
    // left the learner on the completed reel and hid the project transition.
    if (target === null) return;
    pendingInitialKey.current = null;
    pendingInitialIndex.current = null;
    currentIndexRef.current = target;
    setCurrentIndex(target);
    const frame = requestAnimationFrame(() => {
      listRef.current?.scrollToOffset({
        animated: false,
        offset: target * layout.height,
      });
    });
    return () => cancelAnimationFrame(frame);
  }, [feedItems, initialRequestRevision, layout.height]);

  const scrollToIndex = useCallback(
    (index: number, animated = true) => {
      if (!layout.height || index < 0 || index >= feedLengthRef.current) return;
      listRef.current?.scrollToOffset({
        offset: layout.height * index,
        animated,
      });
    },
    [layout.height],
  );

  const scrollToKey = useCallback(
    (key: string) => {
      const index = feedItems.findIndex(item => item.key === key);
      if (index >= 0) scrollToIndex(index);
    },
    [feedItems, scrollToIndex],
  );

  const renderedOwnerGeneration = ownerGeneration.current;
  const onViewableItemsChanged = useCallback(
    ({viewableItems}: {viewableItems: ViewToken<CourseFeedItem>[]}) => {
      if (ownerGeneration.current !== renderedOwnerGeneration) return;
      const height = Math.max(1, frameHeightRef.current);
      const visible = selectPrimaryViewableItem(
        viewableItems,
        scrollOffsetRef.current,
        height,
        scrollDirectionRef.current,
      );
      if (
        typeof visible?.index === 'number' &&
        visible.index !== currentIndexRef.current
      ) {
        void flushPendingPlaybackPositions();
        currentIndexRef.current = visible.index;
        setCurrentIndex(visible.index);
      }
    },
    [ownerGeneration, renderedOwnerGeneration],
  );

  const viewabilityConfig = useRef({
    itemVisiblePercentThreshold: 70,
    minimumViewTime: 80,
  }).current;

  const onLayout = useCallback((event: LayoutChangeEvent) => {
    const {width, height} = event.nativeEvent.layout;
    if (!width || !height) return;
    setLayout(current =>
      current.width === width && current.height === height
        ? current
        : {width, height},
    );
  }, []);

  // A rotation, split-screen resize or fold/unfold changes the paging unit.
  // Re-anchor the same logical item instead of leaving the list between reels.
  useEffect(() => {
    if (!layout.height || !feedItems.length) return;
    const targetIndex = Math.min(
      currentIndexRef.current,
      Math.max(0, feedItems.length - 1),
    );
    if (targetIndex !== currentIndexRef.current) {
      currentIndexRef.current = targetIndex;
      setCurrentIndex(targetIndex);
    }
    const frame = requestAnimationFrame(() => {
      listRef.current?.scrollToOffset({
        animated: false,
        offset: targetIndex * layout.height,
      });
    });
    return () => cancelAnimationFrame(frame);
  }, [feedItems.length, layout.height, layout.width]);

  const onScroll = useCallback((nextOffset: number) => {
    if (Math.abs(nextOffset - scrollOffsetRef.current) > 1) {
      scrollDirectionRef.current =
        nextOffset > scrollOffsetRef.current ? 1 : -1;
    }
    scrollOffsetRef.current = nextOffset;
  }, []);

  const onPagingStarted = useCallback(() => setPaging(true), []);
  const onPagingCancelled = useCallback(() => setPaging(false), []);

  const onPagingSettled = useCallback(
    (nextOffset: number) => {
      onScroll(nextOffset);
      const nextIndex = Math.max(
        0,
        Math.min(
          Math.max(0, feedLengthRef.current - 1),
          Math.round(nextOffset / Math.max(1, frameHeightRef.current)),
        ),
      );
      if (nextIndex !== currentIndexRef.current) {
        void flushPendingPlaybackPositions();
        currentIndexRef.current = nextIndex;
        setCurrentIndex(nextIndex);
      }
      setPaging(false);
    },
    [onScroll],
  );

  const onScrollToIndexFailed = useCallback(
    (index: number) => {
      if (!layout.height || index < 0 || index >= feedLengthRef.current) return;
      listRef.current?.scrollToOffset({
        offset: index * layout.height,
        animated: false,
      });
    },
    [layout.height],
  );

  return {
    currentIndex,
    currentIndexRef,
    feedLengthRef,
    layout,
    listRef,
    onLayout,
    onPagingCancelled,
    onScroll,
    onPagingSettled,
    onPagingStarted,
    onScrollToIndexFailed,
    onViewableItemsChanged,
    paging,
    requestInitialPosition,
    scrollToIndex,
    scrollToKey,
    viewabilityConfig,
  };
};
