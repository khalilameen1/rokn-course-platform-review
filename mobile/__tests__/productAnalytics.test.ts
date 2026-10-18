import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../src/constants/api';
import {
  flushProductEvents,
  readQueuedProductEvents,
  trackProductEvent,
} from '../src/services/productAnalytics';

jest.mock('../src/constants/api', () => ({
  publicRequest: {post: jest.fn()},
}));

describe('product analytics', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
  });

  it('sends a bounded structured event without free-form metadata', async () => {
    (publicRequest.post as jest.Mock).mockResolvedValue({data: {accepted: true}});

    await trackProductEvent({
      event_name: 'course_opened',
      screen_key: 'course_details',
      course_id: 12,
    });
    await flushProductEvents();

    expect(publicRequest.post).toHaveBeenCalledTimes(1);
    expect(publicRequest.post).toHaveBeenCalledWith(
      'product-events',
      {
        events: [
          expect.objectContaining({
            event_name: 'course_opened',
            course_id: 12,
            event_id: expect.stringMatching(/^[a-f0-9-]{36}$/),
          }),
        ],
      },
      {timeout: 6000},
    );
    expect(await readQueuedProductEvents()).toEqual([]);
  });

  it('keeps an event locally when the endpoint is unavailable', async () => {
    (publicRequest.post as jest.Mock).mockRejectedValue(new Error('offline'));

    await trackProductEvent({event_name: 'home_viewed', screen_key: 'home'});
    await flushProductEvents();

    const queued = await readQueuedProductEvents();
    expect(queued).toHaveLength(1);
    expect(queued[0].payload.event_name).toBe('home_viewed');
  });
});
