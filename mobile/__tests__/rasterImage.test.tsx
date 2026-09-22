import React from 'react';
import {Image} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {RasterImage} from '../src/components/ui/RasterImage';

describe('shared raster image policy', () => {
  it('downsamples without changing authenticated sources or event ownership', () => {
    const source = {
      uri: 'https://rokn.app/certificate.png',
      headers: {Authorization: 'Bearer test'},
    };
    const onError = jest.fn();
    const onLoad = jest.fn();
    let view!: TestRenderer.ReactTestRenderer;
    act(() => {
      view = TestRenderer.create(
        <RasterImage
          source={source}
          onError={onError}
          onLoad={onLoad}
          accessibilityLabel="الشهادة"
          style={{width: 200, height: 150}}
          resizeMethod="scale"
        />,
      );
    });
    const native = view.root.findByType(Image);
    expect(native.props.resizeMethod).toBe('resize');
    expect(native.props.source).toBe(source);
    expect(native.props.accessibilityLabel).toBe('الشهادة');
    const event = {nativeEvent: {error: 'offline'}};
    act(() => native.props.onError(event));
    expect(onError).toHaveBeenCalledWith(event);
    act(() => view.unmount());
  });

  it('keeps bundled offline fallback and forwards source changes to the native cache', () => {
    let view!: TestRenderer.ReactTestRenderer;
    act(() => {
      view = TestRenderer.create(
        <RasterImage source={42} defaultSource={42} />,
      );
    });
    expect(view.root.findByType(Image).props.source).toBe(42);
    act(() => {
      view.update(
        <RasterImage
          source={{uri: 'https://rokn.app/new.png'}}
          defaultSource={42}
        />,
      );
    });
    expect(view.root.findByType(Image).props.source.uri).toBe(
      'https://rokn.app/new.png',
    );
    expect(view.root.findByType(Image).props.defaultSource).toBe(42);
    act(() => view.unmount());
  });
});
