import AsyncStorage from '@react-native-async-storage/async-storage';
import {Alert} from 'react-native';
jest.mock('../src/constants/api', () => ({publicRequest: {get: jest.fn(), put: jest.fn()}}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({scope: 'user-a', epoch: 1})),
  assertAccountSessionBoundary: jest.fn(),
  accountScopedStorageKey: jest.fn(async (key: string, boundary: {scope: string}) => `${key}:${boundary.scope}`),
}));
jest.mock('../src/services/publicLinks', () => ({privacyPolicyUrl: 'https://rokn.app/privacy-policy'}));
import {publicRequest} from '../src/constants/api';
import {assertAccountSessionBoundary} from '../src/constants/helpers';
import {AI_CONSENT_VERSION, hasAiConsent, requestAiConsent, requireAiConsent} from '../src/services/aiConsent';

const payload = (accepted: boolean) => ({data: {success: true, data: {
  version: AI_CONSENT_VERSION, accepted, accepted_at: accepted ? '2026-09-12' : null,
}}});
const boundary = {scope: 'user-a', epoch: 1};

beforeEach(async () => {
  jest.clearAllMocks();
  await AsyncStorage.clear();
  (assertAccountSessionBoundary as jest.Mock).mockImplementation(() => undefined);
  (publicRequest.get as jest.Mock).mockResolvedValue(payload(false));
  (publicRequest.put as jest.Mock).mockResolvedValue(payload(true));
});

it('does not send acceptance until the learner explicitly agrees and shares one prompt', async () => {
  const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
  const first = requestAiConsent(boundary);
  const second = requestAiConsent(boundary);
  for (let i = 0; i < 15; i++) await Promise.resolve();
  expect(alert).toHaveBeenCalledTimes(1);
  expect(publicRequest.put).not.toHaveBeenCalled();
  alert.mock.calls[0][2]![2].onPress!();
  await expect(first).resolves.toBe(true);
  await expect(second).resolves.toBe(true);
  expect(publicRequest.put).toHaveBeenCalledWith('ai-consent', {
    version: AI_CONSENT_VERSION, accepted: true,
  }, {timeout: 12000});
  expect(await AsyncStorage.getItem('@rokn/ai-consent/v1:user-a')).toContain(AI_CONSENT_VERSION);
});

it('decline preserves the choice without posting consent or discarding draft storage', async () => {
  await AsyncStorage.setItem('learner-draft', 'keep');
  jest.spyOn(Alert, 'alert').mockImplementation((_title, _text, buttons) => buttons![0].onPress!());
  await expect(requestAiConsent(boundary)).resolves.toBe(false);
  expect(publicRequest.put).not.toHaveBeenCalled();
  expect(await AsyncStorage.getItem('learner-draft')).toBe('keep');
});

it('restores server consent without a repeated prompt after reinstall', async () => {
  (publicRequest.get as jest.Mock).mockResolvedValue(payload(true));
  const alert = jest.spyOn(Alert, 'alert');
  await expect(requestAiConsent(boundary)).resolves.toBe(true);
  expect(alert).not.toHaveBeenCalled();
  expect(publicRequest.put).not.toHaveBeenCalled();
});

it('never treats a local receipt or guest as transmission authority', async () => {
  await AsyncStorage.setItem('@rokn/ai-consent/v1:user-a', JSON.stringify(payload(true)));
  await expect(requireAiConsent(boundary)).rejects.toMatchObject({code: 'ai_consent_required'});
  (publicRequest.get as jest.Mock).mockClear();
  await expect(hasAiConsent({scope: 'guest-a', epoch: 1})).resolves.toBe(false);
  expect(publicRequest.get).not.toHaveBeenCalled();
});

it('cannot apply a prompt acceptance to a different account', async () => {
  jest.spyOn(Alert, 'alert').mockImplementation((_title, _text, buttons) => {
    (assertAccountSessionBoundary as jest.Mock).mockImplementation(() => {throw new Error('ACCOUNT_CHANGED');});
    buttons![2].onPress!();
  });
  await expect(requestAiConsent(boundary)).resolves.toBe(false);
  expect(publicRequest.put).not.toHaveBeenCalled();
});

it('rejects a changed disclosure version instead of silently accepting it', async () => {
  (publicRequest.get as jest.Mock).mockResolvedValue({data: {success: true, data: {
    version: 'future-version', accepted: true,
  }}});
  await expect(requireAiConsent(boundary)).rejects.toThrow('AI_CONSENT_CONTRACT_INVALID');
});
