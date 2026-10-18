import {isUnsupportedVideoPageUri} from '../src/services/videoSourcePolicy';

describe('video source policy', () => {
  it.each([
    'https://www.youtube.com/watch?v=abc',
    'https://m.youtube.com/shorts/abc',
    'https://youtu.be/abc',
    'https://www.youtube-nocookie.com/embed/abc',
  ])('blocks HTML-based YouTube pages before native playback: %s', uri => {
    expect(isUnsupportedVideoPageUri(uri)).toBe(true);
  });

  it.each([
    'https://video.bunnycdn.com/library/video/playlist.m3u8?token=abc',
    'https://cdn.example.com/video.mp4',
    'https://cdn.example.com/signed-stream?id=abc',
  ])('keeps direct media URLs playable: %s', uri => {
    expect(isUnsupportedVideoPageUri(uri)).toBe(false);
  });
});
