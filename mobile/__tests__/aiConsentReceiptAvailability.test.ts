import AsyncStorage from '@react-native-async-storage/async-storage';
import {Alert} from 'react-native';
import type {AccountSessionBoundary} from '../src/constants/helpers';

let mockBoundary: AccountSessionBoundary = {scope: 'user-receipt-1', epoch: 1};
const mockStorageKey = jest.fn();
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), put: jest.fn()},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: AccountSessionBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  accountScopedStorageKey: (...args: unknown[]) => mockStorageKey(...args),
}));
jest.mock('../src/services/publicLinks', () => ({
  privacyPolicyUrl: 'https://rokn.app/privacy-policy',
}));

import {publicRequest} from '../src/constants/api';
import {
  AI_CONSENT_VERSION,
  hasAiConsent,
  manageAiConsent,
  requestAiConsent,
  requireAiConsent,
} from '../src/services/aiConsent';
import {aiConsentPromptHost} from '../src/services/aiConsentPrompt';

const originalSet = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
const response = (accepted: boolean) => ({
  data: {
    success: true,
    data: {
      version: AI_CONSENT_VERSION,
      accepted,
    },
  },
});
const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const tick = async () => {
  for (let i = 0; i < 50; i++) await Promise.resolve();
};
let ownerSequence = 0;

describe('server-owned AI consent with optional local receipts', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    mockBoundary = {
      scope: `user-receipt-${++ownerSequence}`,
      epoch: ownerSequence,
    };
    mockStorageKey
      .mockReset()
      .mockImplementation(
        async (key: string, boundary: AccountSessionBoundary) =>
          `${key}:${boundary.scope}`,
      );
    jest
      .mocked(publicRequest.get)
      .mockReset()
      .mockResolvedValue(response(true) as never);
    jest
      .mocked(publicRequest.put)
      .mockReset()
      .mockResolvedValue(response(true) as never);
  });
  afterEach(async () => {
    await tick();
    jest.restoreAllMocks();
  });

  it.each(['key', 'write', 'failure'] as const)(
    'delivers accepted server consent independently of receipt %s',
    async stage => {
      const key = deferred<string>();
      const write = deferred<void>();
      if (stage === 'key') mockStorageKey.mockReturnValueOnce(key.promise);
      if (stage === 'write')
        jest.spyOn(AsyncStorage, 'setItem').mockReturnValueOnce(write.promise);
      if (stage === 'failure')
        jest
          .spyOn(AsyncStorage, 'setItem')
          .mockRejectedValueOnce(new Error('storage unavailable'));
      let delivered = false;
      const decision = hasAiConsent(mockBoundary).then(value => {
        delivered = true;
        return value;
      });
      try {
        await tick();
        expect(delivered).toBe(true);
        await expect(decision).resolves.toBe(true);
        expect(publicRequest.get).toHaveBeenCalledTimes(1);
        if (stage === 'key')
          expect(AsyncStorage.setItem).not.toHaveBeenCalled();
      } finally {
        key.resolve(`@rokn/ai-consent/v1:${mockBoundary.scope}`);
        write.resolve();
        await tick();
      }
    },
  );

  it('presents the explicit prompt and delivers server acceptance before a blocked earlier receipt is saved', async () => {
    const write = deferred<void>();
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockImplementationOnce((key, value) =>
        write.promise.then(() => originalSet(key, value)),
      );
    jest.mocked(publicRequest.get).mockResolvedValue(response(false) as never);
    const releaseHost = aiConsentPromptHost.register();
    const decision = requestAiConsent(mockBoundary);
    try {
      await tick();
      const prompt = aiConsentPromptHost.getSnapshot();
      expect(prompt).not.toBeNull();
      expect(publicRequest.put).not.toHaveBeenCalled();
      prompt!.decide(true);
      await expect(decision).resolves.toBe(true);
      expect(publicRequest.put).toHaveBeenCalledTimes(1);
      expect(AsyncStorage.setItem).toHaveBeenCalledTimes(1);
    } finally {
      releaseHost();
      write.resolve();
      await tick();
    }
    expect(
      JSON.parse(
        (await AsyncStorage.getItem(
          `@rokn/ai-consent/v1:${mockBoundary.scope}`,
        ))!,
      ).accepted,
    ).toBe(true);
  });

  it('confirms server revocation before receipt writes complete and never infers consent from the receipt', async () => {
    const write = deferred<void>();
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockImplementationOnce((key, value) =>
        write.promise.then(() => originalSet(key, value)),
      );
    jest.mocked(publicRequest.put).mockResolvedValue(response(false) as never);
    const alert = jest
      .spyOn(Alert, 'alert')
      .mockImplementation(() => undefined);
    try {
      await manageAiConsent();
      expect(alert.mock.calls[0][0]).toBe(
        'مشاركة البيانات للمراجعة والاستفسارات',
      );
      alert.mock.calls[0][2]![1].onPress!();
      await tick();
      expect(alert.mock.calls.map(([title]) => title)).toContain(
        'تم إيقاف المشاركة',
      );
      expect(AsyncStorage.setItem).toHaveBeenCalledTimes(1);
      jest
        .mocked(publicRequest.get)
        .mockResolvedValue(response(false) as never);
      await expect(requireAiConsent(mockBoundary)).rejects.toMatchObject({
        code: 'ai_consent_required',
      });
      expect(publicRequest.get).toHaveBeenCalledTimes(2);
    } finally {
      write.resolve();
      await tick();
    }
    expect(
      JSON.parse(
        (await AsyncStorage.getItem(
          `@rokn/ai-consent/v1:${mockBoundary.scope}`,
        ))!,
      ).accepted,
    ).toBe(false);
  });

  it('retires a late old-session receipt before writing the next same-account receipt', async () => {
    const write = deferred<void>();
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockImplementationOnce((key, value) =>
        write.promise.then(() => originalSet(key, value)),
      );
    const removal = jest.spyOn(AsyncStorage, 'removeItem');
    const oldBoundary = {...mockBoundary};
    try {
      await expect(hasAiConsent(oldBoundary)).resolves.toBe(true);
      await tick();
      mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
      jest
        .mocked(publicRequest.get)
        .mockResolvedValue(response(false) as never);
      await expect(hasAiConsent(mockBoundary)).resolves.toBe(false);
      await tick();
      expect(AsyncStorage.setItem).toHaveBeenCalledTimes(1);
    } finally {
      write.resolve();
      await tick();
    }
    const key = `@rokn/ai-consent/v1:${mockBoundary.scope}`;
    expect(removal).toHaveBeenCalledWith(key);
    expect(AsyncStorage.setItem).toHaveBeenCalledTimes(2);
    expect(JSON.parse((await AsyncStorage.getItem(key))!).accepted).toBe(false);
  });
});
