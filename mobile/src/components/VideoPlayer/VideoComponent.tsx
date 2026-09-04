import React, {forwardRef} from 'react';
import VideoSurface from './video/VideoSurface';
import {
  useVideoController,
  type VideoComponentHandle,
  type VideoComponentProps,
} from './video/useVideoController';

const VideoComponent = forwardRef<VideoComponentHandle, VideoComponentProps>(
  (props, forwardedRef) => (
    <VideoSurface {...useVideoController(props, forwardedRef)} />
  ),
);

VideoComponent.displayName = 'VideoComponent';
export default VideoComponent;
export type {VideoComponentHandle, VideoComponentProps};
