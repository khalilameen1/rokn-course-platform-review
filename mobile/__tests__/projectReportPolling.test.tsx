import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockLoadResolution = jest.fn();

jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  loadProjectResolution: (...args: unknown[]) => mockLoadResolution(...args),
  retryProjectReport: jest.fn(),
  retryProjectReview: jest.fn(),
}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(),
}));

import {useProjectResolution} from '../src/components/VideoPlayer/projectTransition/useProjectResolution';
import type {CourseProject} from '../src/components/VideoPlayer/types';

const queuedResolution = {
  status: 'passed' as const,
  canSubmit: false,
  canContinue: false,
  feedbackLevel: 'report' as const,
  reportEnabled: true,
  reportStatus: 'queued' as const,
  replyEnabled: false,
  canRetryReport: false,
  canRetryReview: false,
  feedbackThread: undefined,
};

const project: CourseProject = {
  id: '8',
  sectionId: '80',
  moduleId: '7',
  title: 'مشروع العبور',
  requirements: 'نفّذ المشروع',
  status: 'passed',
  isGraduationProject: false,
  canSubmit: false,
  canContinue: false,
  feedbackLevel: 'report',
  reportEnabled: true,
  reportStatus: 'queued',
};

describe('project report readiness polling', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    mockLoadResolution.mockReset().mockResolvedValue(queuedResolution);
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('keeps checking at a reduced cadence after a long provider queue', async () => {
    const Harness = () => {
      useProjectResolution({
        active: true,
        appIsActive: true,
        project,
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });

    while (mockLoadResolution.mock.calls.length < 31) {
      await act(async () => {
        jest.runOnlyPendingTimers();
        await Promise.resolve();
      });
    }
    expect(mockLoadResolution).toHaveBeenCalledTimes(31);

    await act(async () => {
      jest.runOnlyPendingTimers();
      await Promise.resolve();
    });
    expect(mockLoadResolution).toHaveBeenCalledTimes(32);

    act(() => renderer.unmount());
  });
});
