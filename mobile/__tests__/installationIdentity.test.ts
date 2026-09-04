const mockStorageGet = jest.fn();
const mockStorageSet = jest.fn();

jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: (...args: unknown[]) => mockStorageGet(...args),
  setItem: (...args: unknown[]) => mockStorageSet(...args),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));

import {getInstallationId} from '../src/services/installationIdentity';

describe('installation identity availability', () => {
  it('does not hold a public request behind a stalled native store', async () => {
    jest.useFakeTimers();
    mockStorageGet.mockReturnValue(new Promise(() => undefined));

    const identity = getInstallationId();
    let settled = false;
    void identity.then(() => {
      settled = true;
    });
    await Promise.resolve();
    expect(settled).toBe(false);

    jest.advanceTimersByTime(400);
    await expect(identity).resolves.toBeNull();
    expect(mockStorageSet).not.toHaveBeenCalled();
    jest.useRealTimers();
  });
});
