import React from 'react';
import {Text, TextInput} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

let mockCourseId = '52';
let mockIdentity = 'account-a';
let mockFocused = true;
let mockForeground = true;
const mockCertificates = jest.fn();
const mockLearning = jest.fn();
const mockIssue = jest.fn();
const mockRecover = jest.fn();
const mockNavigate = jest.fn();
const mockGoBack = jest.fn();

jest.mock('@react-navigation/native', () => ({
  useRoute: () => ({params: {courseId: mockCourseId}}),
  createNavigationContainerRef: () => ({}),
  useNavigation: () => ({navigate: mockNavigate, canGoBack: () => true, goBack: mockGoBack}),
  useIsFocused: () => mockFocused,
  useFocusEffect: (effect: () => void | (() => void)) => {
    const ReactModule = require('react') as typeof React;
    ReactModule.useEffect(() => mockFocused ? effect() : undefined, [effect, mockFocused]);
  },
}));
jest.mock('react-redux', () => ({
  useSelector: (selector: (state: unknown) => unknown) => selector({
    auth: {userData: {user: {id: mockIdentity, name: 'طالب ركن'}}},
  }),
}));
jest.mock('../src/constants/helpers', () => ({
  extractUserProfile: (session: {user: unknown}) => session.user,
  sessionIdentityKey: () => mockIdentity,
  captureAccountSessionBoundary: async () => ({scope: mockIdentity}),
  assertAccountSessionBoundary: (boundary: {scope: string}) => {
    if (boundary.scope !== mockIdentity) throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/services/roknApi', () => ({
  getCertificates: () => mockCertificates(),
  getCachedCertificates: async () => [],
  getLearningCourses: () => mockLearning(),
  hasSession: async () => true,
  issueCertificate: (...args: unknown[]) => mockIssue(...args),
  recoverCertificate: (...args: unknown[]) => mockRecover(...args),
}));
jest.mock('../src/services/systemActions', () => ({openExternalUrlOnce: jest.fn(), shareOnce: jest.fn()}));
jest.mock('../src/components/VideoPlayer/attachmentActions', () => ({openCourseAttachment: jest.fn()}));
jest.mock('../src/components/VideoPlayer/attachmentDownloadNotice', () => ({
  useAttachmentDownloadCancellation: () => () => undefined,
}));
jest.mock('../src/hooks/useAppActiveState', () => ({useAppForegroundState: () => mockForeground}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, right: 0, bottom: 0, left: 0}),
}));
jest.mock('../src/constants/designSystem', () => ({
  ...jest.requireActual('../src/constants/designSystem'),
  useResponsiveLayout: () => ({contentWidth: 360, largeText: false}),
}));
jest.mock('../src/components/containers/Containers', () => {
  const {View} = require('react-native');
  return {Container: View, Content: View};
});
jest.mock('../src/components/view/HeaderWithBack', () => () => null);
jest.mock('../src/components/ui/PremiumUI', () => ({
  StatusView: ({title, actionLabel, onAction}: {title: string; actionLabel?: string; onAction?: () => void}) => {
    const ReactModule = require('react') as typeof React;
    const {Text: NativeText, View} = require('react-native');
    return ReactModule.createElement(View, null,
      ReactModule.createElement(NativeText, null, title),
      onAction && ReactModule.createElement(View, {accessibilityLabel: actionLabel, onPress: onAction}),
    );
  },
}));
jest.mock('../src/components/touchables/Button', () => {
  const ReactModule = require('react') as typeof React;
  const {View} = require('react-native');
  return ({title, disable, onPress}: {title: string; disable?: boolean; onPress: () => void}) =>
    ReactModule.createElement(View, {accessibilityLabel: title, onPress: disable ? undefined : onPress});
});
jest.mock('../src/components/ui/QRCode', () => () => null);
jest.mock('../src/screens/Profile/certificates/CertificateArtifactPreview', () => ({
  CertificateArtifactPreview: ({certificateUrl}: {certificateUrl?: string}) => {
    const ReactModule = require('react') as typeof React;
    const {View} = require('react-native');
    return ReactModule.createElement(View, {testID: 'issued-artifact', certificateUrl});
  },
}));

import CourseCertificate from '../src/screens/CourseCertificate';

const course = (id = '52', certificateAvailable = true) => ({
  id, title: `كورس ${id}`, certificateAvailable, progress: 100,
  totalSections: 3, completedSections: 3, accessType: 'paid',
});
const credential = (courseId = '52', status = 'active') => ({
  publicId: `credential-${courseId}`, courseId, status,
  courseName: `كورس ${courseId}`, certificateUrl: `https://rokn.app/c/${courseId}/artifact`,
  certificatePdfUrl: `https://rokn.app/c/${courseId}/download`,
  verificationUrl: `https://rokn.app/c/${courseId}`, qrDestination: null,
});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {resolve = done;});
  return {promise, resolve};
};
let renderer: TestRenderer.ReactTestRenderer | undefined;
const mount = async () => {
  await act(async () => {renderer = TestRenderer.create(<CourseCertificate />);});
};
const press = async (label: string) => {
  const action = renderer!.root.findAll(node =>
    node.props.accessibilityLabel === label && typeof node.props.onPress === 'function',
  )[0];
  if (!action) throw new Error(`Missing action ${label}`);
  await act(async () => {await action.props.onPress();});
};
const texts = () => renderer!.root.findAllByType(Text).map(node => node.props.children).join(' ');

describe('Udemy-style exact-course certificate access with Rokn issuance rules', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockCourseId = '52'; mockIdentity = 'account-a'; mockFocused = true; mockForeground = true;
    mockCertificates.mockReset().mockResolvedValue([]);
    mockLearning.mockReset().mockResolvedValue([course()]);
    mockIssue.mockReset().mockResolvedValue(credential());
    mockRecover.mockReset().mockResolvedValue(null);
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    jest.useRealTimers();
  });

  it('opens only this issued artifact, ignoring unrelated ready and pending courses', async () => {
    mockCertificates.mockResolvedValue([credential('71', 'pending'), credential()]);
    mockLearning.mockResolvedValue([course(), course('71')]);
    await mount();
    expect(renderer!.root.findByProps({testID: 'issued-artifact'}).props.certificateUrl).toBe(credential().certificateUrl);
    expect(texts()).toContain('كورس 52');
    expect(texts()).not.toContain('كورس 71');
    expect(renderer!.root.findAllByType(TextInput)).toHaveLength(0);
    expect(mockIssue).not.toHaveBeenCalled();
    expect(mockRecover).not.toHaveBeenCalled();
    const certificateReads = mockCertificates.mock.calls.length;
    const learningReads = mockLearning.mock.calls.length;
    // Native controls can own unrelated timers. The contract is that this
    // issued course never polls or recovers another course's pending artifact.
    await act(async () => {jest.advanceTimersByTime(60_000);});
    expect(mockCertificates).toHaveBeenCalledTimes(certificateReads);
    expect(mockLearning).toHaveBeenCalledTimes(learningReads);
    expect(mockIssue).not.toHaveBeenCalled();
    expect(mockRecover).not.toHaveBeenCalled();
    expect(renderer!.root.findByProps({testID: 'issued-artifact'}).props.certificateUrl).toBe(credential().certificateUrl);
  });

  it('confirms the frozen name before issuance then opens the canonical returned artifact', async () => {
    await mount();
    const name = renderer!.root.findByType(TextInput);
    expect(name.props.value).toBe('طالب ركن');
    expect(mockIssue).not.toHaveBeenCalled();
    await act(async () => name.props.onChangeText('خليل أمين'));
    await press('إصدار الشهادة');
    expect(mockIssue).toHaveBeenCalledWith('52', 'خليل أمين', {scope: 'account-a'});
    expect(renderer!.root.findByProps({testID: 'issued-artifact'}).props.certificateUrl).toBe(credential().certificateUrl);
    expect(renderer!.root.findAllByType(TextInput)).toHaveLength(0);
    await press('مشاركة الشهادة');
    await press('حفظ الشهادة');
    expect(mockIssue).toHaveBeenCalledTimes(1);
  });

  it('does not infer certificate eligibility from 100 percent progress', async () => {
    mockLearning.mockResolvedValue([course('52', false), course('71')]);
    await mount();
    expect(texts()).toContain('الشهادة غير متاحة الآن');
    expect(renderer!.root.findAllByType(TextInput)).toHaveLength(0);
    expect(mockIssue).not.toHaveBeenCalled();
    await press('العودة للكورس');
    expect(mockNavigate).toHaveBeenCalledWith('CourseDetails', {courseId: '52'});
  });

  it('reads pending status without mutating then opens the completed certificate automatically', async () => {
    mockCertificates.mockResolvedValue([credential('52', 'pending'), credential('71', 'pending')]);
    await mount();
    expect(texts()).toContain('شهادتك قيد التجهيز');
    mockCertificates.mockResolvedValue([credential(), credential('71', 'pending')]);
    await act(async () => {jest.advanceTimersByTime(3000);});
    expect(renderer!.root.findByProps({testID: 'issued-artifact'}).props.certificateUrl).toBe(credential().certificateUrl);
    expect(mockIssue).not.toHaveBeenCalled();
    expect(mockRecover).not.toHaveBeenCalled();
  });

  it('recovers only the requested pending credential after an explicit retry', async () => {
    mockCertificates.mockResolvedValue([credential('52', 'pending'), credential('71', 'pending')]);
    await mount();
    await press('إعادة المحاولة');
    expect(mockRecover).toHaveBeenCalledTimes(1);
    expect(mockRecover).toHaveBeenCalledWith('52', {scope: 'account-a'});
    expect(mockIssue).not.toHaveBeenCalled();
  });

  it('keeps an accepted 202 receipt pending even when its row is not readable yet', async () => {
    mockIssue.mockResolvedValue(null);
    await mount();
    await press('إصدار الشهادة');
    expect(texts()).toContain('شهادتك قيد التجهيز');
    expect(renderer!.root.findAllByType(TextInput)).toHaveLength(0);
    expect(mockIssue).toHaveBeenCalledTimes(1);
    await press('إعادة المحاولة');
    expect(mockRecover).toHaveBeenCalledWith('52', {scope: 'account-a'});
    expect(mockIssue).toHaveBeenCalledTimes(1);
  });

  it('retries a failed canonical read without issuing from progress alone', async () => {
    mockCertificates.mockRejectedValue(new Error('offline'));
    mockLearning.mockRejectedValue(new Error('offline'));
    await mount();
    expect(texts()).toContain('تعذّر تحميل الشهادة');
    expect(mockIssue).not.toHaveBeenCalled();
    mockCertificates.mockResolvedValue([]);
    mockLearning.mockResolvedValue([course()]);
    await press('إعادة المحاولة');
    expect(renderer!.root.findAllByType(TextInput)).toHaveLength(1);
    expect(mockIssue).not.toHaveBeenCalled();
  });

  it('retires the old course owner instead of promoting a late credential on another course page', async () => {
    const old = deferred<unknown[]>();
    mockCertificates.mockReturnValueOnce(old.promise).mockResolvedValue([credential('71')]);
    await mount();
    mockCourseId = '71';
    await act(async () => renderer!.update(<CourseCertificate />));
    await act(async () => old.resolve([credential()]));
    expect(renderer!.root.findByProps({testID: 'issued-artifact'}).props.certificateUrl).toBe(credential('71').certificateUrl);
    expect(texts()).not.toContain('كورس 52');
    expect(mockIssue).not.toHaveBeenCalled();
  });

  it('retires account-owned issuance before its late response can replace another learner credential', async () => {
    const oldIssue = deferred<unknown>();
    mockIssue.mockReturnValue(oldIssue.promise);
    await mount();
    // Keep the mutation in flight; do not await its promise before switching.
    act(() => {
      const action = renderer!.root.findAll(node =>
        node.props.accessibilityLabel === 'إصدار الشهادة' && typeof node.props.onPress === 'function',
      )[0];
      action.props.onPress();
    });
    await act(async () => {await Promise.resolve();});
    expect(mockIssue).toHaveBeenCalledTimes(1);
    mockIdentity = 'account-b';
    mockCertificates.mockResolvedValue([{
      ...credential(), publicId: 'account-b-credential',
      certificateUrl: 'https://rokn.app/c/account-b/artifact',
    }]);
    await act(async () => renderer!.update(<CourseCertificate />));
    await act(async () => oldIssue.resolve(credential()));
    expect(renderer!.root.findByProps({testID: 'issued-artifact'}).props.certificateUrl).toBe('https://rokn.app/c/account-b/artifact');
    expect(mockIssue).toHaveBeenCalledTimes(1);
  });
});
