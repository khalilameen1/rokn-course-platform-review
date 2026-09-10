jest.mock('../src/constants/api', () => ({
  DEFAULT_READ_RECOVERY_BUDGET_MS: 12_000,
  publicRequest: {get: jest.fn()},
}));
jest.mock('../src/components/VideoPlayer/courseLearning/playback', () => ({
  retryPendingSectionCompletions: jest.fn(async () => undefined),
}));

import {publicRequest} from '../src/constants/api';
import {requestCourseAttachmentRenewal} from '../src/services/api/courseDetailsRequest';
import {loadCourseLearningData} from '../src/components/VideoPlayer/courseLearning/mapping';

const get = jest.mocked(publicRequest.get);
const changed = {
  response: {status: 409, data: {code: 'course_revision_changed'}},
};
const response = {
  data: {
    data: {
      id: '3',
      title: 'الكورس',
      modules: [
        {
          id: '1',
          order: 1,
          sections: [
            {
              id: '2',
              type: 'lesson',
              content_id: '4',
              is_preview: true,
              is_locked: false,
              lock_reason: null,
              content: {
                id: '4',
                bunny_video_url: 'https://cdn.example.com/lesson.m3u8',
              },
            },
          ],
        },
      ],
    },
  },
};

describe('fresh course learning reads', () => {
  beforeEach(() => get.mockReset());

  it('preserves the raw attachment renewal read and its original transport options', async () => {
    const rawResponse = {data: {data: {id: 22}}};
    const transport = Promise.resolve(rawResponse);
    get.mockReturnValue(transport);
    const renewal = requestCourseAttachmentRenewal('31', '11');
    expect(renewal).toBe(transport);
    await expect(renewal).resolves.toBe(rawResponse);
    expect(get).toHaveBeenCalledTimes(1);
    expect(get).toHaveBeenCalledWith('courses/31/pdfs/11', {
      timeout: 10_000,
      roknNetworkRetryCount: Number.MAX_SAFE_INTEGER,
    });
  });

  it('leaves attachment revision failures to the download owner without a retry or mapping', async () => {
    get.mockRejectedValue(changed);
    await expect(requestCourseAttachmentRenewal('31', '11')).rejects.toBe(
      changed,
    );
    expect(get).toHaveBeenCalledTimes(1);
  });

  it('retries a publication race once and maps the fresh response', async () => {
    get.mockRejectedValueOnce(changed).mockResolvedValueOnce(response);
    const controller = new AbortController();
    await expect(
      loadCourseLearningData('3', {signal: controller.signal}),
    ).resolves.toMatchObject({course: {id: '3'}});
    expect(get).toHaveBeenCalledTimes(2);
    const first = get.mock.calls[0][1] as {
      signal: AbortSignal;
      roknNetworkRetryDeadlineAt: number;
    };
    const second = get.mock.calls[1][1] as typeof first;
    expect(first.signal).toBe(controller.signal);
    expect(second.signal).toBe(controller.signal);
    expect(first.roknNetworkRetryDeadlineAt).toEqual(expect.any(Number));
    expect(second.roknNetworkRetryDeadlineAt).toBe(
      first.roknNetworkRetryDeadlineAt,
    );
  });

  it('does not retry an indefinitely changing publication', async () => {
    get.mockRejectedValue(changed);
    await expect(loadCourseLearningData('3')).rejects.toMatchObject({
      cause: changed,
    });
    expect(get).toHaveBeenCalledTimes(2);
  });

  it('does not re-read after the screen cancels its request', async () => {
    const controller = new AbortController();
    get.mockImplementationOnce(async () => {
      controller.abort();
      throw changed;
    });
    await expect(
      loadCourseLearningData('3', {signal: controller.signal}),
    ).rejects.toMatchObject({cause: changed});
    expect(get).toHaveBeenCalledTimes(1);
  });

  it.each([401, 403, 404, 409, 500])(
    'does not turn HTTP %s into a publication retry',
    async status => {
      const failure = {response: {status, data: {code: 'other_failure'}}};
      get.mockRejectedValue(failure);
      await expect(loadCourseLearningData('3')).rejects.toMatchObject({
        cause: failure,
      });
      expect(get).toHaveBeenCalledTimes(1);
    },
  );
});
