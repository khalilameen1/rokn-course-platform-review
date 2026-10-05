jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));

import {publicRequest} from '../src/constants/api';
import {getNextEngagementMessage} from '../src/services/api/engagement';
import {getNotificationsPage} from '../src/services/api/notifications';
import {cleanUnicodeText} from '../src/utils/unicodeText';

const mockGet = publicRequest.get as jest.Mock;

const notification = {
  id: 9,
  notification_type: 'new_course',
  title_ar: 'كورس جديد',
  message_ar: 'شاهد التفاصيل',
  action_label_ar: 'افتح الكورس',
  link: '/course/12',
  is_read: false,
  created_at: '2026-09-04T10:00:00+03:00',
};

describe('mobile notification contracts', () => {
  beforeEach(() => jest.clearAllMocks());

  it.each([
    'دورة Grease Pencil',
    'ريلز 2026',
    'شرح SQLSTATE و<input required>',
  ])(
    'keeps authored notification content %s without rejecting the inbox page',
    async authored => {
      mockGet.mockResolvedValue({
        data: {
          data: [{...notification, message_ar: authored}],
          pagination: {current_page: 1, last_page: 1, has_more_pages: false},
        },
      });

      const page = await getNotificationsPage();

      expect(page.notifications).toHaveLength(1);
      expect(cleanUnicodeText(page.notifications[0].description)).toBe(
        authored,
      );
      expect(page.notifications[0].link).toBe(notification.link);
      expect(page.notifications[0].actionLabel).toBe(
        notification.action_label_ar,
      );
    },
  );

  it('keeps authored engagement copy and its action without fabricating missing fields', async () => {
    const campaign = {
      id: '4',
      key: 'coin_offer',
      title_ar: 'دورة Grease Pencil',
      description_ar: 'ريلز 2026: شرح SQLSTATE و<input required>',
      action_label_ar: 'افتح Grease Pencil',
      secondary_action_label_ar: 'لاحقًا',
      task_id: '17',
      campaign_key: 'coin-offer:17',
      link: '/wallet',
    };
    mockGet.mockResolvedValueOnce({data: {data: campaign}});

    const result = await getNextEngagementMessage();

    expect(result).not.toBeNull();
    expect(cleanUnicodeText(result?.title)).toBe(campaign.title_ar);
    expect(cleanUnicodeText(result?.description)).toBe(campaign.description_ar);
    expect(cleanUnicodeText(result?.actionLabel)).toBe(
      campaign.action_label_ar,
    );
    expect(result?.link).toBe('/wallet');
    mockGet.mockResolvedValueOnce({
      data: {data: {...campaign, action_label_ar: ''}},
    });
    await expect(getNextEngagementMessage()).resolves.toBeNull();
  });

  it('keeps the dashboard title body and action as one delivery snapshot', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: [notification],
        pagination: {current_page: 1, last_page: 1, has_more_pages: false},
      },
    });

    await expect(getNotificationsPage()).resolves.toMatchObject({
      notifications: [
        {
          id: '9',
          title: 'كورس جديد',
          description: 'شاهد التفاصيل',
          actionLabel: 'افتح الكورس',
          link: '/course/12',
        },
      ],
    });
  });

  it.each(['title_ar', 'message_ar', 'action_label_ar'])(
    'rejects an inbox row instead of inventing missing %s',
    async missingField => {
      mockGet.mockResolvedValue({
        data: {
          data: [{...notification, [missingField]: ''}],
          pagination: {current_page: 1, last_page: 1, has_more_pages: false},
        },
      });

      await expect(getNotificationsPage()).rejects.toThrow(
        'NOTIFICATIONS_CONTRACT_INVALID',
      );
    },
  );

  it('rejects duplicate ids instead of rendering two cards for one read mutation', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: [notification, {...notification}],
        pagination: {current_page: 1, last_page: 1, has_more_pages: false},
      },
    });

    await expect(getNotificationsPage()).rejects.toThrow(
      'NOTIFICATIONS_CONTRACT_INVALID',
    );
  });

  it('shows only the one personalized unclaimed-task candidate', async () => {
    const base = {
      id: '4',
      key: 'coin_offer',
      title_ar: 'مكافأة جديدة',
      description_ar: 'أكمل المهمة واحصل على العملات',
      action_label_ar: 'افتح المهمة',
      secondary_action_label_ar: 'لاحقًا',
      link: '/wallet',
    };
    mockGet.mockResolvedValueOnce({data: {data: base}});
    await expect(getNextEngagementMessage()).resolves.toBeNull();

    mockGet.mockResolvedValueOnce({
      data: {
        data: {
          ...base,
          task_id: '17',
          campaign_key: 'coin-offer:17',
        },
      },
    });
    await expect(getNextEngagementMessage()).resolves.toMatchObject({
      taskId: '17',
      campaignKey: 'coin-offer:17',
    });
  });
  const homeRow = {
    ...notification,
    course_id: 12,
    home_course: {
      id: 12,
      title: 'عنوان الكورس الحالي',
      image_url: 'https://cdn.example/live-cover.jpg',
    },
  };
  const homeResponse = (rows: unknown[], nextCursor: string | null = null) => ({
    data: {
      data: rows,
      surface: 'home',
      pagination: {
        has_more_pages: nextCursor !== null,
        next_cursor: nextCursor,
      },
    },
  });
  it('requests a dedicated Home slice and retains canonical current course art separate from inbox copy', async () => {
    mockGet.mockResolvedValueOnce(homeResponse([homeRow]));
    const result = await getNotificationsPage({
      surface: 'home',
      cursor: 'older',
    });
    expect(result.notifications[0]).toMatchObject({
      title: notification.title_ar,
      homeCourse: {
        id: '12',
        title: homeRow.home_course.title,
        imageUrl: homeRow.home_course.image_url,
      },
    });
    expect(mockGet).toHaveBeenCalledWith(
      'notifications',
      expect.objectContaining({
        params: expect.objectContaining({
          surface: 'home',
          filter: 'unread',
          cursor: 'older',
          pagination_mode: 'cursor',
        }),
      }),
    );
  });
  it('keeps the next cursor when ownership filtering leaves an empty Home page', async () => {
    mockGet.mockResolvedValueOnce(homeResponse([], 'older'));
    await expect(
      getNotificationsPage({surface: 'home'}),
    ).resolves.toMatchObject({
      notifications: [],
      hasMore: true,
      nextCursor: 'older',
    });
  });
  it('rejects an old server ignoring the Home surface even when its page is empty', async () => {
    mockGet.mockResolvedValueOnce({
      data: {data: [], pagination: {has_more_pages: false}},
    });
    await expect(getNotificationsPage({surface: 'home'})).rejects.toThrow(
      'HOME_NOTIFICATIONS_CONTRACT_INVALID',
    );
  });
  it.each([
    {...homeRow, home_course: null},
    {...homeRow, home_course: {...homeRow.home_course, id: 99}},
    {...homeRow, home_course: {...homeRow.home_course, title: ''}},
    {
      ...homeRow,
      home_course: {
        ...homeRow.home_course,
        image_url: 'http://cdn.example/insecure.jpg',
      },
    },
    {...homeRow, notification_type: 'certificate_ready'},
    {...homeRow, is_read: true},
  ])(
    'rejects a contradictory Home candidate without silently consuming its cursor',
    async row => {
      mockGet.mockResolvedValueOnce(homeResponse([row], 'older'));
      await expect(getNotificationsPage({surface: 'home'})).rejects.toThrow(
        'HOME_NOTIFICATIONS_CONTRACT_INVALID',
      );
    },
  );
});
