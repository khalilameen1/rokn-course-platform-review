import React, {forwardRef} from 'react';
import VideoSurface from './video/VideoSurface';
import {
  useVideoController,
  type VideoComponentHandle,
  type VideoComponentProps,
} from './video/useVideoController';

const ReelPlayer = forwardRef<VideoComponentHandle, VideoComponentProps>(
  (props, forwardedRef) => (
    <VideoSurface {...useVideoController(props, forwardedRef)} />
  ),
);
ReelPlayer.displayName = 'ReelPlayer';

/**
 * One player instance per reel. A virtualized row that receives another reel
 * remounts the player ("Resetting state with a key", React docs), so no
 * timeline, status, recovery or telemetry state can leak between lessons.
 */
const VideoComponent = forwardRef<VideoComponentHandle, VideoComponentProps>(
  (props, forwardedRef) => (
    <ReelPlayer key={props.data.id} {...props} ref={forwardedRef} />
  ),
);

VideoComponent.displayName = 'VideoComponent';
export default VideoComponent;
export type {VideoComponentHandle, VideoComponentProps};
