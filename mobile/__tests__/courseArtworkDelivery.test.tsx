import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Image} from 'expo-image';
import {CourseArtwork} from '../src/components/ui/CourseArtwork';
import {RasterImage} from '../src/components/ui/RasterImage';

describe('course cover delivery through the native cache owner', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const fallback = 42;
  const render = (uri: string) => (
    <CourseArtwork
      fallback={fallback}
      source={{uri}}
      style={{width: 160, height: 240}}
    />
  );
  afterEach(() => act(() => renderer?.unmount()));

  it('uses the existing frame and disk cache without making cover load a startup gate', () => {
    act(() => {
      renderer = TestRenderer.create(
        render('https://rokn.app/storage/courses/previews/a.webp'),
      );
    });
    const image = renderer.root.findByType(Image);
    expect(image.props.source.uri).toContain('/previews/a.webp');
    expect(image.props.style).toEqual({width: 160, height: 240});
    expect(image.props.cachePolicy).toBe('disk');
    expect(image.props.contentFit).toBe('cover');
    expect(image.props.allowDownscaling).toBe(true);
    expect(image.props.recyclingKey).toBe(image.props.source.uri);
    expect(image.props.onLoad).toBeUndefined();
    expect(image.props.onDisplay).toBeUndefined();
  });

  it('falls back for a failed cover but a late former-source error does not replace the new cover', () => {
    act(() => {
      renderer = TestRenderer.create(render('https://rokn.app/a.webp'));
    });
    const formerError = renderer.root.findByType(Image).props.onError;
    act(() => renderer.update(render('https://rokn.app/b.webp')));
    act(() => formerError({error: 'unavailable'}));
    expect(renderer.root.findByType(Image).props.source.uri).toBe(
      'https://rokn.app/b.webp',
    );
    act(() =>
      renderer.root.findByType(Image).props.onError({error: 'unavailable'}),
    );
    act(() => formerError({error: 'late former-source error'}));
    expect(renderer.root.findByType(RasterImage).props.source).toBe(fallback);
    act(() => renderer.update(render('https://rokn.app/c.webp')));
    expect(renderer.root.findByType(Image).props.source.uri).toBe(
      'https://rokn.app/c.webp',
    );
  });

  it('retries a previously failed URI when it becomes the current cover again', () => {
    act(() => {
      renderer = TestRenderer.create(render('https://rokn.app/a.webp'));
    });
    act(() =>
      renderer.root.findByType(Image).props.onError({error: 'offline'}),
    );
    expect(renderer.root.findByType(RasterImage).props.source).toBe(fallback);
    act(() => renderer.update(render('https://rokn.app/b.webp')));
    act(() => renderer.update(render('https://rokn.app/a.webp')));
    expect(renderer.root.findByType(Image).props.source.uri).toBe(
      'https://rokn.app/a.webp',
    );
  });
});
