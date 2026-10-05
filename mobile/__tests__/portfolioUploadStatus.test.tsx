import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {PortfolioUploadStatus} from '../src/screens/Profile/gallery/PortfolioUploadStatus';

describe('portfolio upload status semantics', () => {
  it.each([
    {
      phase: 'uploading' as const,
      percentage: 37,
      label: 'رفع الملفات ٣٧٪',
      now: 37,
    },
    {
      phase: 'uploading' as const,
      percentage: null,
      label: 'رفع الملفات',
      now: undefined,
    },
    {
      phase: 'saving' as const,
      percentage: 100,
      label: 'جار حفظ الملف',
      now: undefined,
    },
    {
      phase: 'finalizing' as const,
      percentage: null,
      label: 'جار حفظ المشروع',
      now: undefined,
    },
  ])(
    'keeps $phase separate from a published/ready project',
    ({phase, percentage, label, now}) => {
      let renderer!: TestRenderer.ReactTestRenderer;
      act(() => {
        renderer = TestRenderer.create(
          <PortfolioUploadStatus
            progress={{phase, percentage, completed: 0, total: 2}}
          />,
        );
      });
      const status = renderer.root.findByProps({
        accessibilityRole: 'progressbar',
      });
      expect(status.props.accessibilityValue).toMatchObject({text: label});
      expect(status.props.accessibilityValue.now).toBe(now);
      act(() => renderer.unmount());
    },
  );
});
