import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

import {useAttachmentPrompt} from '../src/components/VideoPlayer/feedSideBar/useAttachmentPrompt';
import type {
  CourseLearningData,
  CourseReel,
} from '../src/components/VideoPlayer/types';
import {
  hasSeenAttachmentPrompt,
  markAttachmentPromptSeen,
} from '../src/components/VideoPlayer/attachmentPrompt';

let mockBoundary = {epoch: 1, scope: 'user-a'};
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: (boundary: {epoch: number}) => {
    if (boundary.epoch !== mockBoundary.epoch) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  captureAccountSessionBoundary: jest.fn(async () => ({...mockBoundary})),
}));

jest.mock('../src/services/secureSession', () => ({
  peekSecureSession: () => ({epoch: mockBoundary.epoch}),
}));

jest.mock('../src/components/VideoPlayer/attachmentPrompt', () => ({
  hasSeenAttachmentPrompt: jest.fn(),
  markAttachmentPromptSeen: jest.fn(),
}));

const hasSeen = hasSeenAttachmentPrompt as jest.MockedFunction<
  typeof hasSeenAttachmentPrompt
>;
const markSeen = markAttachmentPromptSeen as jest.MockedFunction<
  typeof markAttachmentPromptSeen
>;

const reel: CourseReel = {
  id: 'lesson-1',
  lessonId: 'lesson-1',
  sectionId: 'section-1',
  moduleId: 'module-1',
  title: 'المقطع الأول',
  caption: '',
  videoUrl: 'https://cdn.example/lesson.m3u8',
  availableQualities: ['auto'],
  isPreview: false,
  isLocked: false,
  isCompleted: false,
  reelNumber: 1,
};

const course: CourseLearningData = {
  id: 'course-1',
  title: 'الكورس',
  totalReels: 1,
  attachments: [
    {
      id: 'attachment-1',
      title: 'ملف التطبيق',
      url: 'https://cdn.example/file.pdf',
      platform: 'mobile',
    },
  ],
  attachmentPrompt: {
    enabled: true,
    atSeconds: 5,
    title: 'مرفقات الكورس',
    body: 'حمّل الملفات',
    buttonText: 'تحميل',
    frequency: 'once_per_course',
  },
  modules: [
    {
      id: 'module-1',
      title: 'الوحدة الأولى',
      order: 1,
      isLocked: false,
      reels: [reel],
      projects: [],
    },
  ],
};

let markAttachmentsVisible: () => void = () => undefined;

const Harness = ({
  currentTime,
  present,
}: {
  currentTime: number;
  present: () => void;
}) => {
  const prompt = useAttachmentPrompt({
    course,
    currentTime,
    present,
  });
  markAttachmentsVisible = prompt.markAttachmentsVisible;
  return null;
};

describe('attachment prompt lifecycle', () => {
  beforeEach(() => {
    jest.useRealTimers();
    jest.clearAllMocks();
    mockBoundary = {epoch: 1, scope: 'user-a'};
    markAttachmentsVisible = () => undefined;
    hasSeen.mockResolvedValue(false);
    markSeen.mockResolvedValue(undefined);
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('records the prompt only after the sheet actually becomes visible', async () => {
    const present = jest.fn();
    let renderer: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <Harness currentTime={0} present={present} />,
      );
    });
    await act(async () => {
      renderer!.update(<Harness currentTime={5} present={present} />);
      await Promise.resolve();
    });

    expect(present).toHaveBeenCalledTimes(1);
    expect(markSeen).not.toHaveBeenCalled();

    await act(async () => {
      markAttachmentsVisible();
      await Promise.resolve();
    });

    expect(markSeen).toHaveBeenCalledWith('course-1', 'course', {
      epoch: 1,
      scope: 'user-a',
    });
    await act(async () => {
      renderer!.unmount();
    });
  });

  it('does not enqueue the prompt again while a slow sheet is becoming visible', async () => {
    jest.useFakeTimers();
    const present = jest.fn();
    let renderer: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <Harness currentTime={5} present={present} />,
      );
      await Promise.resolve();
    });

    await act(async () => {
      await jest.advanceTimersByTimeAsync(3_000);
      renderer!.update(<Harness currentTime={8} present={present} />);
      await Promise.resolve();
    });
    expect(present).toHaveBeenCalledTimes(1);

    await act(async () => {
      markAttachmentsVisible();
      await Promise.resolve();
      renderer!.unmount();
    });
    expect(markSeen).toHaveBeenCalledTimes(1);
    jest.useRealTimers();
  });

  it('never records an old visible sheet for the next account', async () => {
    const present = jest.fn();
    let renderer: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <Harness currentTime={5} present={present} />,
      );
      await Promise.resolve();
    });
    expect(present).toHaveBeenCalledTimes(1);

    mockBoundary = {epoch: 2, scope: 'user-b'};
    await act(async () => {
      markAttachmentsVisible();
      await Promise.resolve();
    });
    expect(markSeen).not.toHaveBeenCalled();

    await act(async () => {
      renderer!.update(<Harness currentTime={6} present={present} />);
      await Promise.resolve();
    });
    expect(present).toHaveBeenCalledTimes(2);

    await act(async () => {
      markAttachmentsVisible();
      await Promise.resolve();
      renderer!.unmount();
    });
    expect(markSeen).toHaveBeenCalledWith('course-1', 'course', {
      epoch: 2,
      scope: 'user-b',
    });
  });
});
