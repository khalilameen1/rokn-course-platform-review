import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import type {ProjectFeedbackThread} from '../src/components/VideoPlayer/types';

const mockRead = jest.fn();
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  loadProjectFeedbackThread: (...args: unknown[]) => mockRead(...args),
}));
import {useProjectFeedbackThread} from '../src/components/VideoPlayer/projectTransition/useProjectFeedbackThread';

const exhausted: ProjectFeedbackThread = {
  id: 'thread-7', feedbackLevel: 'enhanced', canReply: true, status: 'ready',
  remainingMessages: 3, replyLimitReached: true,
  messages: [{id: 'report', role: 'assistant', status: 'completed', text: 'تقريرك'}],
};
const replenished = {...exhausted, remainingMessages: 8, replyLimitReached: false};
let current!: ReturnType<typeof useProjectFeedbackThread>;
const Harness = ({active = true, foreground = true, projectId = '7', seed = exhausted}: {
  active?: boolean; foreground?: boolean; projectId?: string; seed?: ProjectFeedbackThread;
}) => {
  current = useProjectFeedbackThread({
    active, appIsActive: foreground, projectId, seedThread: seed,
    feedbackLevel: 'enhanced', replyEnabled: true, reportStatus: 'ready',
  });
  return null;
};

describe('same-level upgraded project capacity GET ownership', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  beforeEach(() => {mockRead.mockReset().mockResolvedValue(replenished);});
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
  });
  it('refreshes actual allowance after a receipt even when the enhanced permission is unchanged', async () => {
    await act(async () => {renderer = TestRenderer.create(<Harness />);});
    expect(mockRead).not.toHaveBeenCalled();
    expect(current.thread?.replyLimitReached).toBe(true);
    await act(async () => {current.refreshRead(); current.refreshRead();});
    expect(mockRead).toHaveBeenCalledTimes(1);
    expect(current.thread?.replyLimitReached).toBe(false);
    expect(current.thread?.remainingMessages).toBe(8);
    expect(current.readRetrying).toBe(false);
  });
  it.each(['close', 'background'] as const)('refreshes cached capacity on return after %s', async reason => {
    await act(async () => {renderer = TestRenderer.create(<Harness />);});
    await act(async () => {
      renderer!.update(<Harness active={reason !== 'close'} foreground={reason !== 'background'} />);
    });
    await act(async () => {current.refreshRead();});
    expect(mockRead).not.toHaveBeenCalled();
    await act(async () => {renderer!.update(<Harness />);});
    expect(mockRead).toHaveBeenCalledTimes(1);
    expect(current.thread?.replyLimitReached).toBe(false);
  });
  it('does not let a previous project receipt refresh another project', async () => {
    await act(async () => {renderer = TestRenderer.create(<Harness />);});
    const oldRefresh = current.refreshRead;
    const other = {...exhausted, id: 'thread-8'};
    await act(async () => {renderer!.update(<Harness projectId="8" seed={other} />);});
    await act(async () => {oldRefresh();});
    expect(mockRead).not.toHaveBeenCalled();
    expect(current.thread?.id).toBe('thread-8');
  });
  it('ignores a late allowance read after replacing the project', async () => {
    let finish!: (thread: ProjectFeedbackThread) => void;
    mockRead.mockReturnValue(new Promise(resolve => {finish = resolve;}));
    await act(async () => {renderer = TestRenderer.create(<Harness />);});
    await act(async () => {current.refreshRead();});
    const other = {...exhausted, id: 'thread-8'};
    await act(async () => {renderer!.update(<Harness projectId="8" seed={other} />);});
    await act(async () => {finish(replenished);});
    expect(current.thread?.id).toBe('thread-8');
    expect(current.thread?.replyLimitReached).toBe(true);
  });
});
