const mockGet = jest.fn();
const mockAssertBoundary = jest.fn();
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: (...args: unknown[]) => mockGet(...args)},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'user-1', epoch: 1}),
  assertAccountSessionBoundary: (...args: unknown[]) =>
    mockAssertBoundary(...args),
}));
jest.mock('../src/services/api/courseAccessAttemptStore', () => ({
  clearCourseUpgradeAttemptKey: jest.fn(),
  getOrCreateCourseUpgradeAttemptKey: jest.fn(),
}));

import {getFullTrackUpgradeQuote} from '../src/services/api/courseUpgrade';

const quote = {
  course_revision: 9,
  already_upgraded: false,
  upgrade_price: 250,
  total_balance: 100,
  spendable_balance: 50,
  deficit: 200,
  reward_contribution_cap_per_course: 0,
  recommended_packages: [],
};
const response = (fields: Record<string, unknown>) => ({
  data: {success: true, data: {...quote, ...fields}},
});

describe('authoritative upgrade availability contract', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockGet.mockReset();
  });

  it('sends the intended feature and returns only server-issued offer codes', async () => {
    mockGet.mockResolvedValue(
      response({upgrade_available: true, available_plan_codes: ['mentor']}),
    );
    const result = await getFullTrackUpgradeQuote('7', {
      requiredFeature: 'chat',
    });
    expect(mockGet).toHaveBeenCalledWith('courses/7/full-track-upgrade', {
      params: {required_feature: 'chat'},
    });
    expect(result).toEqual(
      expect.objectContaining({
        upgradeAvailable: true,
        availablePlanCodes: ['mentor'],
      }),
    );
    expect(mockAssertBoundary).toHaveBeenCalledTimes(2);
  });

  it('does not equate already upgraded with a renewable chat allowance', async () => {
    mockGet.mockResolvedValue(
      response({
        already_upgraded: true,
        chat_available: true,
        upgrade_available: false,
        available_plan_codes: [],
      }),
    );
    const result = await getFullTrackUpgradeQuote('7', {
      requiredFeature: 'chat',
    });
    expect(result).toEqual(
      expect.objectContaining({
        alreadyUpgraded: true,
        upgradeAvailable: false,
        availablePlanCodes: [],
      }),
    );
  });

  it.each([
    {},
    {upgrade_available: true, available_plan_codes: []},
    {upgrade_available: false, available_plan_codes: ['mentor']},
    {upgrade_available: true, available_plan_codes: ['basic']},
  ])(
    'rejects absent or inconsistent availability instead of inventing offers %p',
    async fields => {
      mockGet.mockResolvedValue(response(fields));
      await expect(
        getFullTrackUpgradeQuote('7', {requiredFeature: 'project_discussion'}),
      ).rejects.toThrow('API_CONTRACT_INVALID_COURSE_UPGRADE_AVAILABILITY');
    },
  );

  it('preserves the optional legacy quote contract for callers without a feature intent', async () => {
    mockGet.mockResolvedValue(response({}));
    const result = await getFullTrackUpgradeQuote('7');
    expect(mockGet).toHaveBeenCalledWith('courses/7/full-track-upgrade');
    expect(result.upgradeAvailable).toBeUndefined();
  });
});
