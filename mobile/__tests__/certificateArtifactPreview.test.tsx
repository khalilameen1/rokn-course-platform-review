import React from 'react';
import {Image, StyleSheet, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {CertificateArtifactPreview} from '../src/screens/Profile/certificates/CertificateArtifactPreview';

const firstUrl =
  'https://rokn.app/c/11111111-1111-4111-8111-111111111111/artifact';
const nextUrl =
  'https://rokn.app/c/22222222-2222-4222-8222-222222222222/artifact';

describe('server-issued certificate artwork', () => {
  let renderer: TestRenderer.ReactTestRenderer;

  const render = async (certificateUrl: string | undefined = firstUrl) => {
    await act(async () => {
      renderer = TestRenderer.create(
        <CertificateArtifactPreview
          certificateUrl={certificateUrl}
          courseTitle="مونتاج الريلز"
        />,
      );
    });
  };
  const previewStyle = () =>
    StyleSheet.flatten(
      renderer.root.findByProps({testID: 'certificate-artifact-preview'}).props
        .style,
    );
  const image = () => renderer.root.findByType(Image);
  const text = () =>
    renderer.root.findAllByType(Text).map(node => node.props.children);
  const loaded = async (width: number, height: number) => {
    await act(async () => {
      image().props.onLoad({nativeEvent: {source: {width, height}}});
    });
  };

  afterEach(async () => {
    await act(async () => renderer?.unmount());
  });

  it('loads only the server image with the approved paper and a safe initial ratio', async () => {
    await render();
    expect(image().props.source).toEqual({uri: firstUrl});
    expect(image().props.resizeMode).toBe('contain');
    expect(image().props.accessibilityLabel).toBe('شهادة مونتاج الريلز');
    expect(previewStyle().aspectRatio).toBe(28 / 19);
    expect(previewStyle().backgroundColor).toBe('#fcfcfa');
    expect(StyleSheet.flatten(image().props.style).backgroundColor).toBe(
      '#fcfcfa',
    );
    expect(text()).toContain('جارٍ تحميل الشهادة');
  });

  it.each([
    [1400, 950, 28 / 19],
    [2800, 1900, 28 / 19],
    [1200, 900, 4 / 3],
  ])(
    'fits a %s × %s artifact without cropping',
    async (width, height, ratio) => {
      await render();
      await loaded(width, height);
      expect(previewStyle().aspectRatio).toBe(ratio);
      expect(image().props.resizeMode).toBe('contain');
      expect(text()).not.toContain('جارٍ تحميل الشهادة');
      expect(StyleSheet.flatten(image().props.style).opacity).not.toBe(0);
    },
  );

  it.each([
    [0, 950],
    [1400, 0],
    [-1400, 950],
    [Number.NaN, 950],
    [1400, Number.POSITIVE_INFINITY],
    [Number.MAX_VALUE, Number.MIN_VALUE],
    [Number.MIN_VALUE, Number.MAX_VALUE],
  ])(
    'keeps the safe ratio for invalid dimensions %s × %s',
    async (width, height) => {
      await render();
      await loaded(width, height);
      expect(previewStyle().aspectRatio).toBe(28 / 19);
      expect(text()).not.toContain('جارٍ تحميل الشهادة');
    },
  );

  it('resets for a different artifact and ignores late events from its predecessor', async () => {
    await render();
    const previousImage = image().props;
    await loaded(1200, 900);
    expect(previewStyle().aspectRatio).toBe(4 / 3);
    await act(async () => {
      renderer.update(
        <CertificateArtifactPreview
          certificateUrl={nextUrl}
          courseTitle="مبادئ التسويق"
        />,
      );
    });
    expect(previewStyle().aspectRatio).toBe(28 / 19);
    expect(text()).toContain('جارٍ تحميل الشهادة');
    expect(image().props.source).toEqual({uri: nextUrl});
    await act(async () => {
      previousImage.onLoad({nativeEvent: {source: {width: 1200, height: 900}}});
      previousImage.onError();
    });
    expect(previewStyle().aspectRatio).toBe(28 / 19);
    expect(text()).toContain('جارٍ تحميل الشهادة');
    expect(image().props.source).toEqual({uri: nextUrl});
    await loaded(2800, 1900);
    expect(text()).toEqual([]);
  });

  it('shows an honest failure instead of synthesizing a local certificate', async () => {
    await render();
    await act(async () => image().props.onError());
    expect(renderer.root.findAllByType(Image)).toHaveLength(0);
    expect(text()).toContain('تعذّر تحميل صورة الشهادة');
    await act(async () => {
      renderer.update(
        <CertificateArtifactPreview
          certificateUrl={nextUrl}
          courseTitle="مبادئ التسويق"
        />,
      );
    });
    expect(image().props.source).toEqual({uri: nextUrl});
    expect(text()).toContain('جارٍ تحميل الشهادة');
  });

  it('keeps a pending artifact as a compact placeholder until its URL arrives', async () => {
    await act(async () => {
      renderer = TestRenderer.create(
        <CertificateArtifactPreview
          courseTitle="مبادئ التسويق"
          compact
          pending
        />,
      );
    });
    expect(renderer.root.findAllByType(Image)).toHaveLength(0);
    expect(text()).toContain('نجهّز الشهادة');
    expect(previewStyle().aspectRatio).toBe(28 / 19);
    await act(async () => {
      renderer.update(
        <CertificateArtifactPreview
          certificateUrl={firstUrl}
          courseTitle="مبادئ التسويق"
          compact
        />,
      );
    });
    expect(image().props.source).toEqual({uri: firstUrl});
    await loaded(2800, 1900);
    expect(text()).toEqual([]);
  });
});
