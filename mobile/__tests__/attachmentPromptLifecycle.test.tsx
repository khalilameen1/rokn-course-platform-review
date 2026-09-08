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
import {captureAccountSessionBoundary} from '../src/constants/helpers';

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
let openAttachments: () => void = () => undefined;

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<T>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return {promise, resolve, reject};
};

const Harness = ({
  currentTime,
  present,
  value = course,
}: {
  currentTime: number;
  present: () => void;
  value?: CourseLearningData;
}) => {
  const prompt = useAttachmentPrompt({
    course: value,
    currentTime,
    present,
  });
  markAttachmentsVisible = prompt.markAttachmentsVisible;
  openAttachments = prompt.openAttachments;
  return null;
};

describe('attachment prompt lifecycle', () => {
  beforeEach(() => {
    jest.useRealTimers();
    jest.clearAllMocks();
    mockBoundary = {epoch: 1, scope: 'user-a'};
    markAttachmentsVisible = () => undefined;
    openAttachments = () => undefined;
    (captureAccountSessionBoundary as jest.Mock).mockImplementation(
      async () => ({...mockBoundary}),
    );
    hasSeen.mockResolvedValue(false);
    markSeen.mockResolvedValue(undefined);
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('keeps one pending storage check across playback progress and presents its result', async () => {
    const read = deferred<boolean>();
    hasSeen.mockReturnValue(read.promise);
    const present = jest.fn();
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <Harness currentTime={5} present={present} />,
      );
    });
    try {
      for (const time of [5.25, 5.5, 6]) {
        await act(async () =>
          renderer.update(<Harness currentTime={time} present={present} />),
        );
      }
      expect(hasSeen).toHaveBeenCalledTimes(1);
      await act(async () => read.resolve(false));
      expect(present).toHaveBeenCalledTimes(1);
      expect(markSeen).not.toHaveBeenCalled();
    } finally {
      act(() => renderer.unmount());
    }
  });

  it('can check again after a delayed storage failure while playback continues', async () => {
    const read = deferred<boolean>();
    hasSeen.mockReturnValueOnce(read.promise).mockResolvedValue(false);
    const present = jest.fn();
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <Harness currentTime={5} present={present} />,
      );
    });
    try {
      await act(async () =>
        renderer.update(<Harness currentTime={6} present={present} />),
      );
      await act(async () => read.reject(new Error('storage unavailable')));
      await act(async () =>
        renderer.update(<Harness currentTime={7} present={present} />),
      );
      expect(hasSeen).toHaveBeenCalledTimes(2);
      expect(present).toHaveBeenCalledTimes(1);
    } finally {
      act(() => renderer.unmount());
    }
  });

  it('does not open a previous course after a delayed manual account restore', async () => {
    const restore = deferred<typeof mockBoundary>();
    (captureAccountSessionBoundary as jest.Mock).mockReturnValueOnce(
      restore.promise,
    );
    const present = jest.fn();
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <Harness currentTime={0} present={present} />,
      );
    });
    try {
      await act(async () => openAttachments());
      expect(captureAccountSessionBoundary).toHaveBeenCalledTimes(1);
      await act(async () =>
        renderer.update(
          <Harness
            currentTime={0}
            present={present}
            value={{...course, id: 'course-2'}}
          />,
        ),
      );
      await act(async () => restore.resolve({...mockBoundary}));
      expect(present).not.toHaveBeenCalled();
    } finally {
      act(() => renderer.unmount());
    }
  });

  it('does not duplicate a manual presentation while an older automatic read finishes', async () => {
    const read = deferred<boolean>();
    hasSeen.mockReturnValue(read.promise);
    const present = jest.fn();
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <Harness currentTime={5} present={present} />,
      );
    });
    try {
      await act(async () => openAttachments());
      expect(present).toHaveBeenCalledTimes(1);
      await act(async () => read.resolve(false));
      expect(present).toHaveBeenCalledTimes(1);
      await act(async () => markAttachmentsVisible());
      expect(markSeen).toHaveBeenCalledTimes(1);
    } finally {
      act(() => renderer.unmount());
    }
  });

  it('keeps account restoration as one flight while video time advances', async () => {
    const restore = deferred<typeof mockBoundary>();
    (captureAccountSessionBoundary as jest.Mock).mockReturnValue(
      restore.promise,
    );
    const present = jest.fn();
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <Harness currentTime={5} present={present} />,
      );
    });
    try {
      await act(async () =>
        renderer.update(<Harness currentTime={6} present={present} />),
      );
      await act(async () =>
        renderer.update(<Harness currentTime={7} present={present} />),
      );
      expect(captureAccountSessionBoundary).toHaveBeenCalledTimes(1);
      await act(async () => restore.resolve({...mockBoundary}));
      expect(hasSeen).toHaveBeenCalledTimes(1);
      expect(present).toHaveBeenCalledTimes(1);
    } finally {
      act(() => renderer.unmount());
    }
  });

  it.each(['automatic', 'manual'] as const)(
    'ignores a pending %s presentation after unmount',
    async mode => {
      const restore = deferred<typeof mockBoundary>();
      (captureAccountSessionBoundary as jest.Mock).mockReturnValue(
        restore.promise,
      );
      const present = jest.fn();
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(
          <Harness
            currentTime={mode === 'automatic' ? 5 : 0}
            present={present}
          />,
        );
      });
      if (mode === 'manual') await act(async () => openAttachments());
      act(() => renderer.unmount());
      await act(async () => restore.resolve({...mockBoundary}));
      expect(present).not.toHaveBeenCalled();
      expect(markSeen).not.toHaveBeenCalled();
    },
  );

  it('cancels an automatic read after seeking before the trigger and can try at the trigger again', async () => {
    const read = deferred<boolean>();
    hasSeen.mockReturnValueOnce(read.promise).mockResolvedValue(false);
    const present = jest.fn();
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <Harness currentTime={5} present={present} />,
      );
    });
    try {
      await act(async () =>
        renderer.update(<Harness currentTime={2} present={present} />),
      );
      await act(async () => read.resolve(false));
      expect(present).not.toHaveBeenCalled();
      await act(async () =>
        renderer.update(<Harness currentTime={5} present={present} />),
      );
      expect(hasSeen).toHaveBeenCalledTimes(2);
      expect(present).toHaveBeenCalledTimes(1);
    } finally {
      act(() => renderer.unmount());
    }
  });

  it('retries an unpresented sheet after its grace period on the next playback tick', async () => {
    jest.useFakeTimers();
    const present = jest.fn();
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(
        <Harness currentTime={5} present={present} />,
      );
    });
    try {
      await act(async () => jest.advanceTimersByTimeAsync(5_100));
      expect(present).toHaveBeenCalledTimes(1);
      await act(async () =>
        renderer.update(<Harness currentTime={6} present={present} />),
      );
      expect(present).toHaveBeenCalledTimes(2);
      expect(markSeen).not.toHaveBeenCalled();
      await act(async () => markAttachmentsVisible());
      expect(markSeen).toHaveBeenCalledTimes(1);
    } finally {
      act(() => renderer.unmount());
    }
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
