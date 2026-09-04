jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));

import {publicRequest} from '../src/constants/api';
import {getNextEngagementMessage} from '../src/services/api/engagement';
import {getNotificationsPage} from '../src/services/api/notifications';

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

  it('rejects an inbox row instead of inventing a missing dashboard button', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: [{...notification, action_label_ar: ''}],
        pagination: {current_page: 1, last_page: 1, has_more_pages: false},
      },
    });

    await expect(getNotificationsPage()).rejects.toThrow(
      'NOTIFICATIONS_CONTRACT_INVALID',
    );
  });

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
});
