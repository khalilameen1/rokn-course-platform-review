import React from 'react';
import {StyleSheet, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {Palette} from '../src/constants/designSystem';

let mockLargeText = false;
let mockHasDownloads = true;
let mockCancelDownload: jest.Mock | undefined;
const mockCloseCertificate = jest.fn();
const mockOpenCertificate = jest.fn();
const mockSaveCertificate = jest.fn();
const mockShareCertificate = jest.fn();

let mockQrDestination: {
  type: 'portfolio' | 'certificate';
  url: string;
  title: string;
  hint: string;
} | null = {
  type: 'portfolio',
  url: 'https://rokn.app/@rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
  title: 'شاهد الأعمال',
  hint: 'امسح الرمز لعرضها',
};

jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({navigate: jest.fn()}),
}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, right: 0, bottom: 0, left: 0}),
}));
jest.mock('../src/constants/designSystem', () => ({
  ...jest.requireActual('../src/constants/designSystem'),
  useResponsiveLayout: () => ({contentWidth: 360, largeText: mockLargeText}),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
jest.mock('../src/components/touchables/Button', () => () => null);
jest.mock('../src/components/FullTrackUpgradeSheet', () => () => null);
jest.mock('../src/components/ui/PremiumUI', () => ({
  MetaPill: () => null,
  SectionHeading: () => null,
  StatusView: () => null,
}));
jest.mock(
  '../src/screens/Profile/certificates/CertificateArtifactPreview',
  () => ({CertificateArtifactPreview: () => null}),
);
jest.mock(
  '../src/components/ui/QRCode',
  () =>
    ({
      value,
      accessibilityLabel,
    }: {
      value: string;
      accessibilityLabel: string;
    }) => {
      const ReactModule = require('react') as typeof React;
      const {View: NativeView} = require('react-native');
      return ReactModule.createElement(NativeView, {
        testID: `qr:${value}`,
        accessibilityLabel,
      });
    },
);
jest.mock(
  '../src/screens/Profile/certificates/useCertificatesController',
  () => ({
    useCertificatesController: () => ({
      activeCertificateQrDestination: mockQrDestination,
      activeCourseTitle: 'كورس تجريبي',
      activeCredential: '11111111-1111-4111-8111-111111111111',
      certificatePending: false,
      certificates: [],
      closeIssueCertificate: jest.fn(),
      closeSelectedCertificate: mockCloseCertificate,
      confirmIssueCertificate: jest.fn(),
      grantCourses: [],
      identityOwned: true,
      issueCourse: null,
      issueName: '',
      issuing: false,
      loadCertificates: jest.fn(),
      loadError: '',
      loading: false,
      openCertificate: mockOpenCertificate,
      openIssueCertificate: jest.fn(),
      readyCourses: [],
      recoverPendingCertificates: jest.fn(),
      retryPendingCertificate: jest.fn(),
      saveCertificate: mockSaveCertificate,
      cancelCertificateDownload: mockCancelDownload,
      selectCertificate: jest.fn(),
      selectedCertificate: {
        publicId: '11111111-1111-4111-8111-111111111111',
        courseId: '52',
        certificateUrl: mockHasDownloads
          ? 'https://rokn.app/c/11111111-1111-4111-8111-111111111111/artifact'
          : '',
        certificatePdfUrl: mockHasDownloads
          ? 'https://rokn.app/c/11111111-1111-4111-8111-111111111111/download'
          : '',
        qrDestination: mockQrDestination,
      },
      selectedGrantCourse: null,
      selectGrantCourse: jest.fn(),
      setIssueName: jest.fn(),
      shareCertificate: mockShareCertificate,
      serverSession: true,
    }),
  }),
);

import Certificates from '../src/screens/Profile/Certificates';

const certificateActions = (renderer: TestRenderer.ReactTestRenderer) =>
  renderer.root.findAll(
    node =>
      node.props.accessibilityRole === 'button' &&
      typeof node.props.onPress === 'function' &&
      typeof node.props.style === 'function',
  );

describe('certificate QR destination', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockLargeText = false;
    mockHasDownloads = true;
    mockCancelDownload = undefined;
    mockQrDestination = {
      type: 'portfolio',
      url: 'https://rokn.app/@rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
      title: 'شاهد الأعمال',
      hint: 'امسح الرمز لعرضها',
    };
  });

  it.each([
    [
      'portfolio',
      'https://rokn.app/@rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
      'شاهد الأعمال',
    ],
    [
      'certificate',
      'https://rokn.app/c/11111111-1111-4111-8111-111111111111',
      'تحقق من الشهادة',
    ],
  ] as const)(
    'renders the %s destination supplied by the API',
    async (type, url, title) => {
      mockQrDestination = {
        type,
        url,
        title,
        hint:
          type === 'portfolio'
            ? 'امسح الرمز لعرضها'
            : 'امسح الرمز لعرض بياناتها',
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(<Certificates />);
      });

      expect(renderer.root.findByProps({testID: `qr:${url}`})).toBeTruthy();
      expect(
        renderer.root.findByProps({testID: `qr:${url}`}).props
          .accessibilityLabel,
      ).toBe(
        type === 'portfolio'
          ? 'رمز QR لعرض الأعمال'
          : 'رمز QR للتحقق من الشهادة',
      );
      expect(
        renderer.root.findAllByProps({children: title}).length,
      ).toBeGreaterThan(0);
      const visibleCopy = renderer.root
        .findAllByType(Text)
        .map(node => JSON.stringify(node.props.children))
        .join('\n');
      expect(visibleCopy).not.toContain(url);
      expect(visibleCopy).not.toContain(mockQrDestination.hint);
      expect(visibleCopy).not.toContain('قابلة للتحقق والمشاركة');
      expect(visibleCopy).not.toContain('شهادة موثقة من ركن');
      expect(visibleCopy).toContain('11111111-1111-4111-8111-111111111111');

      await act(async () => renderer.unmount());
    },
  );

  it('keeps sharing primary and all certificate actions connected', async () => {
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Certificates />);
    });
    const actions = certificateActions(renderer);
    const action = (label: string) => {
      const found = actions.find(
        node => node.props.accessibilityLabel === label,
      );
      if (!found) throw new Error(`Missing certificate action: ${label}`);
      return found;
    };
    const share = action('مشاركة الشهادة');
    const save = action('حفظ الشهادة');
    const verify = action('التحقق من الشهادة');
    const close = action('إغلاق تفاصيل الشهادة');
    const style = (node: TestRenderer.ReactTestInstance) =>
      StyleSheet.flatten(node.props.style({pressed: false}));
    expect(style(share).backgroundColor).toBe(Palette.primary);
    expect(style(save).backgroundColor).toBe(Palette.surface);
    expect(style(verify).backgroundColor).toBe(Palette.surface);
    [share, save, verify, close].forEach(node => {
      expect(node.props.accessibilityRole).toBe('button');
      expect(style(node).minHeight).toBeGreaterThanOrEqual(48);
    });
    expect(actions.indexOf(share)).toBeLessThan(actions.indexOf(save));
    expect(actions.indexOf(share)).toBeLessThan(actions.indexOf(verify));

    await act(async () => {
      share.props.onPress();
      save.props.onPress();
      verify.props.onPress();
      close.props.onPress();
    });
    expect(mockShareCertificate).toHaveBeenCalledTimes(1);
    expect(mockSaveCertificate).toHaveBeenCalledTimes(1);
    expect(mockOpenCertificate).toHaveBeenCalledTimes(1);
    expect(mockCloseCertificate).toHaveBeenCalledTimes(1);
    const detailModal = renderer.root.findAll(
      node =>
        node.props.visible === true &&
        typeof node.props.onRequestClose === 'function',
    )[0];
    await act(async () => detailModal!.props.onRequestClose());
    expect(mockCloseCertificate).toHaveBeenCalledTimes(2);
    await act(async () => renderer.unmount());
  });

  it('replaces save with cancellation while a download is active', async () => {
    mockCancelDownload = jest.fn();
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Certificates />);
    });
    const actions = certificateActions(renderer);
    expect(
      actions.some(node => node.props.accessibilityLabel === 'حفظ الشهادة'),
    ).toBe(false);
    const cancel = actions.find(
      node => node.props.accessibilityLabel === 'إلغاء تنزيل شهادة كورس تجريبي',
    );
    expect(cancel).toBeDefined();
    await act(async () => cancel!.props.onPress());
    expect(mockCancelDownload).toHaveBeenCalledTimes(1);
    expect(mockSaveCertificate).not.toHaveBeenCalled();
    await act(async () => renderer.unmount());
  });

  it('omits saving when no artifact can be downloaded without hiding share or verification', async () => {
    mockHasDownloads = false;
    mockQrDestination = null;
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Certificates />);
    });
    const actions = certificateActions(renderer);
    const labels = actions.map(node => node.props.accessibilityLabel);
    expect(labels).not.toContain('حفظ الشهادة');
    expect(labels).toContain('مشاركة الشهادة');
    expect(labels).toContain('التحقق من الشهادة');
    expect(
      renderer.root.findAll(
        node =>
          typeof node.props.testID === 'string' &&
          node.props.testID.startsWith('qr:'),
      ),
    ).toHaveLength(0);
    await act(async () => renderer.unmount());
  });

  it('stacks secondary actions for large text without truncating labels', async () => {
    mockLargeText = true;
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Certificates />);
    });
    const actions = certificateActions(renderer);
    ['حفظ الشهادة', 'التحقق من الشهادة'].forEach(label => {
      const action = actions.find(
        node => node.props.accessibilityLabel === label,
      )!;
      const style = StyleSheet.flatten(action.props.style({pressed: false}));
      expect(style.width).toBe('100%');
      expect(style.flexBasis).toBe('auto');
      const text = action.findByType(Text);
      expect(text.props.numberOfLines).toBeUndefined();
      expect(text.props.allowFontScaling).not.toBe(false);
    });
    await act(async () => renderer.unmount());
  });
});
