import React from 'react';
import {Image, Platform, StyleSheet, View} from 'react-native';
import Video, {
  BufferingStrategyType,
  ViewType,
} from 'react-native-video';
import {VideoChrome} from './VideoChrome';
import {VIDEO_BITRATE_BY_QUALITY} from './policy';
import type {VideoController} from './useVideoController';

const VideoSurface = (controller: VideoController) => {
  const {data, playbackEligible, playbackPaused} = controller;
  const videoKey = `${data.id}-${data.playbackSessionId || 'local'}-${
    data.playbackManifestRevision || 0
  }-${controller.effectiveQuality}-${
    controller.usingFallback ? 'fallback' : 'primary'
  }-${controller.retryKey}`;

  return (
    <View
      style={[
        styles.container,
        {width: controller.width, height: controller.height},
      ]}>
      {!controller.isLoaded && !!data.thumbnailUrl && (
        <Image
          accessibilityElementsHidden
          accessibilityIgnoresInvertColors
          importantForAccessibility="no"
          blurRadius={3}
          source={{uri: data.thumbnailUrl}}
          style={StyleSheet.absoluteFill}
        />
      )}
      {controller.appIsActive && !controller.unsupportedSource && (
        <Video
          key={videoKey}
          ref={controller.videoRef}
          source={controller.source}
          resizeMode="cover"
          viewType={Platform.OS === 'android' ? ViewType.TEXTURE : undefined}
          shutterColor="#030507"
          paused={!playbackEligible || playbackPaused}
          muted={!playbackEligible}
          repeat={false}
          rate={controller.playbackSpeed}
          selectedVideoTrack={controller.selectedVideoTrack}
          controls={false}
          playInBackground={false}
          playWhenInactive={false}
          progressUpdateInterval={playbackEligible ? 1000 : 2500}
          reportBandwidth={playbackEligible}
          ignoreSilentSwitch="ignore"
          mixWithOthers="inherit"
          disableFocus={!playbackEligible || controller.pausedByUser}
          automaticallyWaitsToMinimizeStalling
          preferredForwardBufferDuration={playbackEligible ? 6 : 1}
          bufferingStrategy={
            Platform.OS === 'android'
              ? BufferingStrategyType.DEPENDING_ON_MEMORY
              : undefined
          }
          preventsDisplaySleepDuringVideoPlayback={
            playbackEligible && !playbackPaused
          }
          onAudioBecomingNoisy={controller.handleAudioBecomingNoisy}
          onAudioFocusChanged={controller.handleAudioFocusChanged}
          style={StyleSheet.absoluteFill}
          bufferConfig={
            playbackEligible
              ? {
                  minBufferMs: 4000,
                  maxBufferMs: 18000,
                  bufferForPlaybackMs: 1200,
                  bufferForPlaybackAfterRebufferMs: 2500,
                  maxHeapAllocationPercent: 0.24,
                  minBufferMemoryReservePercent: 0.15,
                }
              : {
                  minBufferMs: 900,
                  maxBufferMs: 2600,
                  bufferForPlaybackMs: 600,
                  bufferForPlaybackAfterRebufferMs: 900,
                  maxHeapAllocationPercent: 0.12,
                  minBufferMemoryReservePercent: 0.15,
                }
          }
          maxBitRate={
            playbackEligible
              ? VIDEO_BITRATE_BY_QUALITY[controller.effectiveQuality]
              : 750_000
          }
          {...controller.videoEventHandlers}
        />
      )}

      <VideoChrome
        bottomInset={controller.bottomInset}
        currentTime={controller.currentTime}
        failureKind={controller.failureKind}
        isBuffering={controller.isBuffering}
        isLoaded={controller.isLoaded}
        onRetry={controller.retryPlayback}
        onSeekBy={controller.seekBy}
        onTogglePaused={controller.togglePaused}
        onTrackWidth={controller.setTrackWidth}
        panHandlers={controller.panHandlers}
        pausedByUser={playbackPaused}
        previewTime={controller.previewTime}
        recoveryMessage={controller.recoveryMessage}
        sourceFailed={controller.sourceFailed}
        timeline={controller.timeline}
        trackWidth={controller.trackWidth}
        unsupportedSource={controller.unsupportedSource}
      />
    </View>
  );
};

export default VideoSurface;

const styles = StyleSheet.create({
  container: {
    backgroundColor: '#030507',
    overflow: 'hidden',
  },
});
