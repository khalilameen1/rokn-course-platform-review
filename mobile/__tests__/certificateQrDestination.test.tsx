import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

let mockQrDestination: {
  type: 'portfolio' | 'certificate';
  url: string;
  title: string;
  hint: string;
} = {
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
  useResponsiveLayout: () => ({contentWidth: 360}),
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
jest.mock('../src/components/ui/QRCode', () => ({value}: {value: string}) => {
  const ReactModule = require('react') as typeof React;
  const {View: NativeView} = require('react-native');
  return ReactModule.createElement(NativeView, {
    accessibilityLabel: `qr:${value}`,
  });
});
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
      closeSelectedCertificate: jest.fn(),
      confirmIssueCertificate: jest.fn(),
      grantCourses: [],
      identityOwned: true,
      issueCourse: null,
      issueName: '',
      issuing: false,
      loadCertificates: jest.fn(),
      loadError: '',
      loading: false,
      openCertificate: jest.fn(),
      openIssueCertificate: jest.fn(),
      readyCourses: [],
      recoverPendingCertificates: jest.fn(),
      retryPendingCertificate: jest.fn(),
      saveCertificate: jest.fn(),
      selectCertificate: jest.fn(),
      selectedCertificate: {
        publicId: '11111111-1111-4111-8111-111111111111',
        courseId: '52',
        certificateUrl:
          'https://rokn.app/c/11111111-1111-4111-8111-111111111111/artifact',
        certificatePdfUrl:
          'https://rokn.app/c/11111111-1111-4111-8111-111111111111/download',
        qrDestination: mockQrDestination,
      },
      selectedGrantCourse: null,
      selectGrantCourse: jest.fn(),
      setIssueName: jest.fn(),
      shareCertificate: jest.fn(),
      serverSession: true,
    }),
  }),
);

import Certificates from '../src/screens/Profile/Certificates';

describe('certificate QR destination', () => {
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

      expect(
        renderer.root.findByProps({accessibilityLabel: `qr:${url}`}),
      ).toBeTruthy();
      expect(
        renderer.root.findAllByProps({children: title}).length,
      ).toBeGreaterThan(0);

      await act(async () => renderer.unmount());
    },
  );
});
