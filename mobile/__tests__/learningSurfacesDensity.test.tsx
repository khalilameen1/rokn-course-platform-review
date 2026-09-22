import React from 'react';
import {Text, TextInput, StyleSheet} from 'react-native';
import {cleanUnicodeText} from '../src/utils/unicodeText';
import TestRenderer, {act} from 'react-test-renderer';
import Module from '../src/components/view/Module';
import {FeedbackForm} from '../src/screens/feedback/FeedbackForm';
import {ProfessionalProgress} from '../src/screens/myCorner/ProfessionalProgress';
import type {CourseLearningModule} from '../src/components/VideoPlayer/types';
import type {LearningPathProgress} from '../src/services/roknApi';

const mockNavigate = jest.fn();
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({navigate: mockNavigate}),
}));
jest.mock('react-native-linear-gradient', () => 'LinearGradient');
jest.mock('react-native-svg', () => ({
  __esModule: true,
  default: 'Svg',
  Path: 'Path',
}));

const courseModule = {
  id: 'module',
  order: 1,
  title: 'التصميم',
  isLocked: false,
  reels: [
    {
      id: 'reel',
      moduleId: 'module',
      title: 'الخطوة الأولى',
      reelNumber: 1,
      sectionOrder: 1,
      isCompleted: true,
      isLocked: false,
    },
  ],
  projects: [
    {
      id: 'project',
      moduleId: 'module',
      sectionId: 'section',
      title: 'تطبيق عملي',
      sectionOrder: 2,
      requirements: 'تفاصيل طويلة محفوظة داخل المشروع',
      status: 'not_submitted',
      isGraduationProject: false,
    },
  ],
} as unknown as CourseLearningModule;

const junior = {id: '1', name: 'Junior', order: 1};
const mid = {id: '2', name: 'Mid-level', order: 2};
const senior = {id: '3', name: 'Senior', order: 3};
const path: LearningPathProgress = {
  id: 'path',
  title: 'التصميم',
  currentLevel: junior,
  nextLevel: mid,
  upcomingLevels: [mid, senior],
  progress: 30,
  remainingToNextLevel: 70,
  completedSections: 3,
  totalSections: 10,
};

describe('Compact learning surfaces preserve their actions', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  afterEach(() => {
    act(() => renderer?.unmount());
    mockNavigate.mockReset();
  });
  const texts = () =>
    renderer.root
      .findAllByType(Text)
      .map(node => String(node.props.children))
      .join('\n');
  const pressLabel = (label: string) =>
    renderer.root
      .findAll(node => typeof node.props.onPress === 'function')
      .find(node =>
        node
          .findAllByType(Text)
          .some(
            text => cleanUnicodeText(String(text.props.children)) === label,
          ),
      )!;

  it('opens the project directly from its row without repeating the requirements', () => {
    act(() => {
      renderer = TestRenderer.create(
        <Module courseId="course" module={courseModule} initiallyExpanded />,
      );
    });
    expect(texts()).not.toContain(courseModule.projects![0].requirements);
    const project = pressLabel('تطبيق عملي');
    act(() => project.props.onPress());
    expect(mockNavigate).toHaveBeenCalledWith('Reels', {
      courseId: 'course',
      projectId: 'project',
      reelId: undefined,
      lessonId: undefined,
      preview: false,
      previewCount: undefined,
    });
    const rowStyle = StyleSheet.flatten(project.props.style({pressed: false}));
    expect(rowStyle.minHeight).toBeGreaterThanOrEqual(48);
  });

  it('keeps server-locked rows disabled and exposes why they are locked', () => {
    act(() => {
      renderer = TestRenderer.create(
        <Module
          courseId="course"
          module={{
            ...courseModule,
            isLocked: true,
            lockReason: 'purchase_required',
          }}
          initiallyExpanded
        />,
      );
    });
    expect(pressLabel('تطبيق عملي').props.disabled).toBe(true);
    expect(pressLabel('الخطوة الأولى').props.disabled).toBe(true);
    expect(pressLabel('تطبيق عملي').props.accessibilityHint).toBeTruthy();
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it('shows current and next ranks first with the rest available on demand', () => {
    const props = {
      badges: [{id: 'badge', title: 'شارة مكتسبة', order: 1}],
      earnedBadge: true,
      largeText: true,
      learningPaths: [path],
      nextLevel: mid,
      onSelectPath: jest.fn(),
      pathProgress: 30,
      selectedPath: path,
      visible: true,
    };
    act(() => {
      renderer = TestRenderer.create(<ProfessionalProgress {...props} />);
    });
    expect(texts()).toContain('Junior');
    expect(texts()).toContain('Mid-level');
    expect(texts()).not.toContain('Senior');
    expect(texts()).not.toContain('شارة مكتسبة');
    act(() => pressLabel('كل المستويات').props.onPress());
    expect(texts()).toContain('Senior');
    act(() => pressLabel('شاراتك المكتسبة').props.onPress());
    expect(texts()).toContain('شارة مكتسبة');
    act(() => {
      renderer.update(
        <ProfessionalProgress
          {...props}
          selectedPath={{...path, id: 'another'}}
        />,
      );
    });
    expect(texts()).not.toContain('Senior');
    expect(texts()).not.toContain('شارة مكتسبة');
  });

  it('keeps help input, attachments and explicit diagnostic consent without repeated labels', () => {
    const toggle = jest.fn();
    const props: React.ComponentProps<typeof FeedbackForm> = {
      ready: true,
      busy: false,
      canSubmit: true,
      category: 'problem',
      draftSaveError: false,
      error: '',
      includeDiagnostics: false,
      message: 'المقطع لا يعمل',
      onChooseAttachment: jest.fn(),
      onMessageChange: jest.fn(),
      onRemoveAttachment: jest.fn(),
      onSelectCategory: jest.fn(),
      onToggleDiagnostics: toggle,
      onSubmit: jest.fn(),
    };
    act(() => {
      renderer = TestRenderer.create(<FeedbackForm {...props} />);
    });
    expect(texts()).not.toContain('نوع الرسالة');
    expect(texts()).not.toContain('١٦٠٠');
    expect(renderer.root.findByType(TextInput).props.maxLength).toBe(1600);
    const consent = renderer.root
      .findAll(node => typeof node.props.onPress === 'function')
      .find(node => node.props.accessibilityRole === 'checkbox')!;
    expect(consent.props.accessibilityState.checked).toBe(false);
    act(() => consent.props.onPress());
    expect(toggle).toHaveBeenCalledWith(true);
    act(() => {
      renderer.update(<FeedbackForm {...props} message={'س'.repeat(1450)} />);
    });
    expect(texts()).toContain('١٦٠٠');
  });
});
