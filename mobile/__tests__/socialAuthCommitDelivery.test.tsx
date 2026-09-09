import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import * as Crypto from 'expo-crypto';

const mockDispatch = jest.fn();
const mockPost = jest.fn();
const mockNavigation = {
  addListener: jest.fn(() => () => undefined),
  reset: jest.fn(),
  navigate: jest.fn(),
};
const mockCallback = `rokn://auth?attempt=${'C'.repeat(43)}&code=one-use-code`;
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => mockNavigation,
  useRoute: () => ({
    params: {returnTo: {name: 'CourseDetails', params: {courseId: '3'}}},
  }),
}));
jest.mock('react-redux', () => ({
  useDispatch: () => mockDispatch,
  useSelector: () => null,
}));
jest.mock('../src/store/reducers/auth', () => ({
  LogOut: () => ({type: 'logout'}),
  saveLoginData: (session: unknown) => ({type: 'login', payload: session}),
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {post: (...args: unknown[]) => mockPost(...args)},
}));
jest.mock('../src/services/installationIdentity', () => ({
  getRequiredInstallationId: async () => '11111111-1111-4111-8111-111111111111',
}));
jest.mock('../src/services/socialAuth', () => ({
  getSocialAuthMethods: async () => ({providers: ['google']}),
  signInWithSocialProvider: () =>
    jest
      .requireActual('../src/services/socialAuthCompletion')
      .resumePendingSocialAuth(mockCallback),
}));
jest.mock('../src/services/guestAccountMigration', () => ({
  stageGuestAccountMigration: async () => undefined,
  resumeCompleteGuestAccountMigration: async () => undefined,
}));
jest.mock('../src/navigation/authReturn', () => ({
  savePendingLoginReturnTo: async () => undefined,
  clearPendingLoginReturnTo: async () => undefined,
  loginReturnResetState: jest.fn(),
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('../src/services/smartReminders', () => ({
  cancelLearningReminders: jest.fn(async () => undefined),
  setSmartRemindersEnabled: jest.fn(async () => undefined),
}));
jest.mock('../src/services/pushNotifications', () => ({
  getCurrentPushDeviceToken: jest.fn(async () => null),
  clearCurrentPushDeviceRegistration: jest.fn(async () => undefined),
}));
jest.mock('../src/services/deviceSessions', () => ({
  revokeCurrentDeviceSession: jest.fn(async () => undefined),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  clearCurrentAccountLearningFiles: jest.fn(async () => undefined),
}));
jest.mock('../src/utils/fileCache', () => ({
  clearTransientChatCache: jest.fn(async () => undefined),
}));
jest.mock('../src/components/auth/SocialAuthView', () => () => null);

import SocialAuthShell from '../src/components/auth/SocialAuthShell';
import SocialAuthView from '../src/components/auth/SocialAuthView';
import {resumePendingSocialAuth} from '../src/services/socialAuthCompletion';
import {
  clearPendingWelcomeBonus,
  getPendingWelcomeBonus,
} from '../src/services/pendingWelcomeBonus';
import {PENDING_SOCIAL_AUTH_KEY} from '../src/services/secureSessionStorage';
import {
  loadSecureSession,
  extractApiToken,
  loadPendingSocialAuthAttempt,
  peekSecureSession,
  resetSecureSessionForTests,
  savePendingSocialAuthAttempt,
  saveSecureSession,
} from '../src/services/secureSession';

describe('committed social authentication reaches its real UI consumer', () => {
  it.each([
    'welcome',
    'journal_read',
    'journal_delete',
    'credential',
    'journal_commit',
    'welcome_account_change',
    'capture_account_change',
  ])(
    'delivers committed credentials across stalled %s storage, never bypassing credential persistence',
    async stage => {
      jest.useFakeTimers();
      const secure = new Map<string, string>();
      const disk = new Map<string, string>();
      resetSecureSessionForTests();
      jest.clearAllMocks();
      (AsyncStorage.getItem as jest.Mock).mockImplementation(
        async key => disk.get(key) ?? null,
      );
      (AsyncStorage.removeItem as jest.Mock).mockImplementation(async key => {
        disk.delete(key);
      });
      let releaseReceipt!: () => void;
      let receiptStarted!: () => void;
      const receiptReady = new Promise<void>(resolve => {
        receiptStarted = resolve;
      });
      const receipt = new Promise<void>(resolve => {
        releaseReceipt = resolve;
      });
      const digest = (
        Crypto.digestStringAsync as jest.Mock
      ).getMockImplementation()!;
      let captureDelayed = false;
      (Crypto.digestStringAsync as jest.Mock).mockImplementation(
        async (...args: unknown[]) => {
          if (
            stage === 'capture_account_change' &&
            !captureDelayed &&
            args[1] === '52' &&
            extractApiToken(peekSecureSession().session) === 'completed-bearer'
          ) {
            captureDelayed = true;
            receiptStarted();
            await receipt;
          }
          return digest(...args);
        },
      );
      (SecureStore.getItemAsync as jest.Mock).mockImplementation(async key => {
        if (
          stage === 'journal_read' &&
          key === PENDING_SOCIAL_AUTH_KEY &&
          extractApiToken(peekSecureSession().session)
        ) {
          receiptStarted();
          await receipt;
        }
        return secure.get(key) ?? null;
      });
      (SecureStore.setItemAsync as jest.Mock).mockImplementation(
        async (key, value) => {
          if (
            (stage === 'credential' && key === 'rokn.auth.api-token.v2') ||
            (stage === 'journal_commit' &&
              key === PENDING_SOCIAL_AUTH_KEY &&
              value.includes('completedSession'))
          ) {
            receiptStarted();
            await receipt;
          }
          secure.set(key, value);
        },
      );
      (SecureStore.deleteItemAsync as jest.Mock).mockImplementation(
        async key => {
          if (stage === 'journal_delete' && key === PENDING_SOCIAL_AUTH_KEY) {
            receiptStarted();
            await receipt;
          }
          secure.delete(key);
        },
      );
      (AsyncStorage.setItem as jest.Mock).mockImplementation(
        async (key: string, value: string) => {
          if (
            stage.startsWith('welcome') &&
            key.startsWith('@rokn/pending-welcome-bonus/')
          ) {
            receiptStarted();
            await receipt;
          }
          disk.set(key, value);
        },
      );
      await loadSecureSession();
      await savePendingSocialAuthAttempt({
        provider: 'google',
        verifier: 'V'.repeat(64),
        challenge: 'C'.repeat(43),
        flow: 'browser',
        startedAt: new Date().toISOString(),
      });
      const session = {
        api_token: 'completed-bearer',
        welcome_bonus_granted: 20,
        user: {id: 52, name: 'Learner', social_provider: 'google'},
      };
      mockPost.mockResolvedValue({
        data: {status: 200, success: true, data: session},
      });
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        await act(async () => {
          renderer = TestRenderer.create(<SocialAuthShell />);
        });
        await act(async () => {
          renderer.root.findByType(SocialAuthView).props.onContinue('google');
          await receiptReady;
          if (stage.endsWith('account_change')) {
            await saveSecureSession({
              api_token: 'replacement-bearer',
              user: {id: 53, name: 'Another learner'},
            });
            await savePendingSocialAuthAttempt({
              provider: 'google',
              verifier: 'N'.repeat(64),
              challenge: 'D'.repeat(43),
              flow: 'browser',
              startedAt: new Date().toISOString(),
            });
            renderer.unmount();
          }
          await jest.advanceTimersByTimeAsync(800);
        });
        if (stage === 'credential' || stage === 'journal_commit') {
          expect(extractApiToken(peekSecureSession().session)).not.toBe(
            session.api_token,
          );
          expect(mockDispatch).not.toHaveBeenCalled();
          expect(renderer.root.findByType(SocialAuthView).props.loading).toBe(
            'google',
          );
        } else if (stage.endsWith('account_change')) {
          expect(extractApiToken(peekSecureSession().session)).toBe(
            'replacement-bearer',
          );
          expect(mockDispatch).not.toHaveBeenCalled();
        } else {
          expect(extractApiToken(peekSecureSession().session)).toBe(
            session.api_token,
          );
          expect(mockDispatch).toHaveBeenCalledWith({
            type: 'login',
            payload: expect.objectContaining({api_token: session.api_token}),
          });
          expect(
            renderer.root.findByType(SocialAuthView).props.loading,
          ).toBeNull();
        }
        expect(mockPost).toHaveBeenCalledTimes(1);
        if (stage === 'welcome' || stage === 'journal_delete') {
          if (stage === 'journal_delete') {
            expect(await getPendingWelcomeBonus()).toBe(20);
            await clearPendingWelcomeBonus();
          }
          const committedEpoch = peekSecureSession().epoch;
          let resumed!: ReturnType<typeof resumePendingSocialAuth>;
          await act(async () => {
            resumed = resumePendingSocialAuth();
          });
          await act(async () => {
            await jest.advanceTimersByTimeAsync(800);
          });
          await expect(resumed).resolves.toMatchObject({
            api_token: session.api_token,
          });
          expect(peekSecureSession().epoch).toBe(committedEpoch);
          expect(mockPost).toHaveBeenCalledTimes(1);
          expect(
            (AsyncStorage.setItem as jest.Mock).mock.calls.filter(([key]) =>
              String(key).startsWith('@rokn/pending-welcome-bonus/'),
            ),
          ).toHaveLength(1);
          if (stage === 'journal_delete') {
            expect(await getPendingWelcomeBonus()).toBeNull();
          }
        }
      } finally {
        releaseReceipt();
        await act(async () => {
          await jest.advanceTimersByTimeAsync(0);
          renderer?.unmount();
        });
        jest.useRealTimers();
        (Crypto.digestStringAsync as jest.Mock).mockImplementation(digest);
      }
      if (stage.endsWith('account_change')) {
        expect(mockDispatch).not.toHaveBeenCalled();
        expect((await loadPendingSocialAuthAttempt())?.verifier).toBe(
          'N'.repeat(64),
        );
        expect(await getPendingWelcomeBonus()).toBeNull();
      } else {
        expect(mockDispatch).toHaveBeenCalledWith({
          type: 'login',
          payload: expect.objectContaining({api_token: session.api_token}),
        });
      }
    },
  );
});
