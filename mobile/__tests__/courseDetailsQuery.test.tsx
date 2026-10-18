import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';
import type {QueryClient} from '@tanstack/react-query';
import type {CourseDetails} from '../src/services/roknApi';
import {
  createTestQueryClient,
  withAppQueryProvider,
  withQueryClient,
} from './helpers/queryClient';

let mockScope = 'user-1';
const mockDetails = jest.fn();
jest.mock('../src/services/roknApi', () => ({
  getCourseDetailsSnapshot: (...args: unknown[]) => mockDetails(...args),
  getWallet: async () => {
    throw new Error('not used');
  },
  hasSession: async () => mockScope.startsWith('user-'),
  isCourseUnavailableError: (error: {status?: number}) =>
    [403, 404, 410].includes(Number(error?.status)),
}));
jest.mock('../src/constants/helpers', () => ({
  ...jest.requireActual('../src/constants/helpers'),
  captureAccountSessionBoundary: async () => ({scope: mockScope, epoch: 1}),
  assertAccountSessionBoundary: (boundary: {scope: string}) => {
    if (boundary.scope !== mockScope) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
}));
jest.mock('../src/services/walletSettlement', () => ({
  subscribeWalletSettlements: () => () => undefined,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  mapCoursePayload: () => ({id: '3', modules: []}),
  applyLocalLearningState: async (value: unknown) => value,
}));

import {useCourseDetailsData} from '../src/screens/CourseDetails/details/useCourseDetailsData';
import {attachQueryPersistence} from '../src/services/query/queryPersistence';

const course = (overrides: Partial<CourseDetails> = {}): CourseDetails =>
  ({
    id: '3',
    publishedRevision: 1,
    title: 'كورس ركن',
    description: '',
    price: 0,
    owned: false,
    accessPlans: [],
    modules: [],
    fromCache: false,
    ...overrides,
  } as CourseDetails);
const snapshot = (details: CourseDetails) => ({
  course: details,
  responsePayload: details.owned ? {data: {}} : null,
});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return {promise, resolve, reject};
};
const offline = () =>
  Object.assign(new Error('Network Error'), {code: 'ERR_NETWORK'});

describe('course details query', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let data!: ReturnType<typeof useCourseDetailsData>;
  let client: QueryClient;
  const Harness = ({identityKey}: {identityKey: string}) => {
    data = useCourseDetailsData({courseId: '3', identityKey});
    return null;
  };

  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    mockScope = 'user-1';
    client = createTestQueryClient();
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
  });

  it('keeps a purchase acknowledged while an older details read is in flight', async () => {
    mockDetails.mockResolvedValueOnce(snapshot(course()));
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<Harness identityKey="account-1" />, client),
      );
    });
    expect(data.course.value?.owned).toBe(false);

    const olderRead = deferred<ReturnType<typeof snapshot>>();
    const afterPurchase = deferred<ReturnType<typeof snapshot>>();
    mockDetails
      .mockReturnValueOnce(olderRead.promise)
      .mockReturnValueOnce(afterPurchase.promise);
    await act(async () => data.course.reload());
    await act(async () => data.course.setOwned(true));
    expect(data.course.value?.owned).toBe(true);
    expect(data.course.loading).toBe(true);

    // The read that predates the purchase cannot relock the course.
    await act(async () => olderRead.resolve(snapshot(course())));
    expect(data.course.value?.owned).toBe(true);

    await act(async () =>
      afterPurchase.resolve(snapshot(course({owned: true}))),
    );
    expect(data.course.value?.owned).toBe(true);
    expect(data.course.loading).toBe(false);
    expect(data.course.learningValue).not.toBeNull();

    // Every later read is authoritative again: a refund relocks.
    mockDetails.mockResolvedValueOnce(snapshot(course({owned: false})));
    await act(async () => data.course.reload());
    expect(data.course.value?.owned).toBe(false);
  });

  it('keeps signed-in details on a failed refresh with the network notice', async () => {
    mockDetails.mockResolvedValueOnce(snapshot(course()));
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<Harness identityKey="account-1" />, client),
      );
    });
    mockDetails.mockRejectedValueOnce(offline());
    await act(async () => data.course.reload());
    expect(data.course.value?.title).toBe('كورس ركن');
    expect(data.course.value?.fromCache).toBe(false);
    expect(data.course.error).toBe('');
    expect(data.course.notice).not.toBe('');
  });

  it('shows an unavailable course as such, without its old details', async () => {
    mockDetails.mockResolvedValueOnce(snapshot(course()));
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<Harness identityKey="account-1" />, client),
      );
    });
    mockDetails.mockRejectedValueOnce({status: 404});
    await act(async () => data.course.reload());
    expect(data.course.value).toBeNull();
    expect(data.course.error).toBe(
      'هذا الكورس غير متاح الآن\nعد إلى الرئيسية واختر كورسًا آخر',
    );
  });

  it('shows a guest the saved details only after the server read fails', async () => {
    // A previous guest visit saved this course on the device.
    mockScope = 'guest-1';
    const previous = createTestQueryClient();
    const saving = attachQueryPersistence(previous, {throttleTime: 0});
    await saving.activateAccountScope('guest-1');
    previous.setQueryData(['guest-1', 'course-details', '3'], {
      course: course({title: 'نسخة محفوظة'}),
      learning: null,
      learningRead: null,
    });
    await new Promise(resolve => setTimeout(resolve, 60));
    saving.dispose();

    const read = deferred<ReturnType<typeof snapshot>>();
    mockDetails.mockReturnValueOnce(read.promise);
    await act(async () => {
      renderer = TestRenderer.create(
        withAppQueryProvider(<Harness identityKey="guest" />, client),
      );
    });
    await act(async () => {
      await new Promise(resolve => setTimeout(resolve, 40));
    });
    // Network first: the saved copy never stands in for a pending read.
    expect(data.course.loading).toBe(true);
    expect(data.course.value).toBeNull();

    await act(async () => read.reject(offline()));
    expect(data.course.value).toMatchObject({
      title: 'نسخة محفوظة',
      fromCache: true,
    });
    expect(data.course.notice).toBe(
      'نعرض آخر تفاصيل محفوظة\nسنحدّثها عند عودة الاتصال',
    );
    expect(data.course.error).toBe('');
    expect(data.course.prepareLearningNavigation()).toBeUndefined();
  });
});
