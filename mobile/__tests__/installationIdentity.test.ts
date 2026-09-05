const mockStorageGet = jest.fn();
const mockStorageSet = jest.fn();

jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: (...args: unknown[]) => mockStorageGet(...args),
  setItem: (...args: unknown[]) => mockStorageSet(...args),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));

import {
  getInstallationId,
  getRequiredInstallationId,
} from '../src/services/installationIdentity';

describe('installation identity availability', () => {
  it('keeps public reads bounded while authentication joins the durable identity write', async () => {
    jest.useFakeTimers();
    let releaseStorageRead!: (value: null) => void;
    mockStorageGet.mockReturnValue(
      new Promise(resolve => {
        releaseStorageRead = resolve;
      }),
    );
    mockStorageSet.mockResolvedValue(undefined);

    const identity = getInstallationId();
    const requiredIdentity = getRequiredInstallationId();
    let settled = false;
    void identity.then(() => {
      settled = true;
    });
    await Promise.resolve();
    expect(settled).toBe(false);

    jest.advanceTimersByTime(400);
    await expect(identity).resolves.toBeNull();
    expect(mockStorageSet).not.toHaveBeenCalled();

    releaseStorageRead(null);
    await Promise.resolve();
    await expect(requiredIdentity).resolves.toBe(
      '11111111-1111-4111-8111-111111111111',
    );
    expect(mockStorageSet).toHaveBeenCalledWith(
      '@rokn/installation-id/v1',
      '11111111-1111-4111-8111-111111111111',
    );
    jest.useRealTimers();
  });
});
