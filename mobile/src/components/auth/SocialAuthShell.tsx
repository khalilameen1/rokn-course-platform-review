import {useNavigation, useRoute} from '@react-navigation/native';
import type {RouteProp} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import React, {useCallback, useEffect, useReducer, useRef} from 'react';
import {Alert} from 'react-native';
import {useDispatch, useSelector} from 'react-redux';
import {
  extractApiToken,
  getCurrentAccountStorageScope,
} from '../../constants/helpers';
import {LogOut, saveLoginData} from '../../store/reducers/auth';
import {
  signInWithSocialProvider,
  getSocialAuthMethods,
} from '../../services/socialAuth';
import type {
  SocialAuthMethods,
  SocialProvider,
} from '../../services/socialAuth';
import {
  socialAuthFailureCode,
  socialAuthMessage,
} from '../../services/socialAuthErrors';
import {
  assertSecureSessionStorageAvailable,
  deletePendingSocialAuthAttempt,
  loadPendingSocialAuthAttempt,
  peekSecureSession,
} from '../../services/secureSession';
import {reportClientError} from '../../services/operationalTelemetry';
import type {LoginRouteParams} from '../../navigation/types';
import type {RootState} from '../../store/store';
import {
  resumeCompleteGuestAccountMigration,
  stageGuestAccountMigration,
} from '../../services/guestAccountMigration';
import {
  acknowledgePendingLoginReturnTo,
  claimPendingLoginReturnTo,
  clearPendingLoginReturnTo,
  loginReturnResetState,
  savePendingLoginReturnTo,
} from '../../navigation/authReturn';
import {learnerFacingText} from '../../utils/errorPayload';
import {serverNowMs} from '../../utils/serverClock';
import {settleWithin} from '../../utils/settleWithin';
import SocialAuthView from './SocialAuthView';

type LoginRoute = RouteProp<{Login: LoginRouteParams}, 'Login'>;

type AuthFlowState =
  | {phase: 'discovering'; methods: null; provider: null}
  | {
      phase: 'discovery_failed';
      methods: null;
      provider: null;
      failureCode: string;
    }
  | {phase: 'ready'; methods: SocialAuthMethods; provider: null}
  | {
      phase: 'authorizing';
      methods: SocialAuthMethods;
      provider: SocialProvider;
    };

type AuthFlowEvent =
  | {type: 'discover'}
  | {type: 'discovered'; methods: SocialAuthMethods}
  | {type: 'discovery_failed'; failureCode: string}
  | {type: 'authorize'; provider: SocialProvider}
  | {type: 'authorization_finished'};

const authFlowReducer = (
  state: AuthFlowState,
  event: AuthFlowEvent,
): AuthFlowState => {
  switch (event.type) {
    case 'discover':
      return {phase: 'discovering', methods: null, provider: null};
    case 'discovered':
      return {phase: 'ready', methods: event.methods, provider: null};
    case 'discovery_failed':
      return {
        phase: 'discovery_failed',
        methods: null,
        provider: null,
        failureCode: event.failureCode,
      };
    case 'authorize':
      return state.phase === 'ready' &&
        state.methods.providers.includes(event.provider)
        ? {
            phase: 'authorizing',
            methods: state.methods,
            provider: event.provider,
          }
        : state;
    case 'authorization_finished':
      return state.phase === 'authorizing'
        ? {phase: 'ready', methods: state.methods, provider: null}
        : state;
  }
};

export default function SocialAuthShell() {
  const navigation = useNavigation<RootNavigation>();
  const route = useRoute<LoginRoute>();
  const dispatch = useDispatch();
  const currentSession = useSelector((state: RootState) => state.auth.userData);
  const [authFlow, sendAuthFlow] = useReducer(authFlowReducer, {
    phase: 'discovering',
    methods: null,
    provider: null,
  });
  const authMethods = authFlow.methods;
  const loading = authFlow.phase === 'authorizing' ? authFlow.provider : null;
  const authMethodsGenerationRef = useRef(0);
  const authMethodsRequestRef = useRef<Promise<SocialAuthMethods> | null>(null);
  const authIntentGenerationRef = useRef(0);
  const authAttemptInFlightRef = useRef(false);

  const loadAuthMethods = useCallback(async () => {
    const generation = ++authMethodsGenerationRef.current;
    sendAuthFlow({type: 'discover'});
    const request = authMethodsRequestRef.current ?? getSocialAuthMethods();
    authMethodsRequestRef.current = request;
    try {
      const methods = await request;
      if (generation === authMethodsGenerationRef.current) {
        sendAuthFlow({type: 'discovered', methods});
      }
      return methods;
    } catch (error) {
      if (generation === authMethodsGenerationRef.current) {
        sendAuthFlow({
          type: 'discovery_failed',
          failureCode: socialAuthFailureCode(error),
        });
      }
      throw error;
    } finally {
      if (authMethodsRequestRef.current === request) {
        authMethodsRequestRef.current = null;
      }
    }
  }, []);

  const finishExistingSessionNavigation = () => {
    navigation.reset(
      loginReturnResetState(route.params?.returnTo, 'authenticated'),
    );
    void clearPendingLoginReturnTo().catch(() => undefined);
  };

  useEffect(() => {
    void loadAuthMethods().catch(() => undefined);
    return () => {
      authMethodsGenerationRef.current += 1;
      authIntentGenerationRef.current += 1;
    };
  }, [loadAuthMethods]);

  useEffect(
    () =>
      navigation.addListener('beforeRemove', () => {
        if (
          authAttemptInFlightRef.current ||
          extractApiToken(currentSession) ||
          extractApiToken(peekSecureSession().session)
        ) {
          return;
        }
        // Back means the learner abandoned this login journey. Retaining its
        // route reopened Login on the next cold start, while retaining its
        // encrypted provider attempt allowed a late Android callback to sign
        // in after the learner had explicitly left the screen.
        const abandonedAt = serverNowMs();
        authIntentGenerationRef.current += 1;
        void claimPendingLoginReturnTo()
          .then(claim =>
            claim && claim.createdAt < abandonedAt
              ? acknowledgePendingLoginReturnTo(claim.receipt)
              : false,
          )
          .catch(() => undefined);
        void loadPendingSocialAuthAttempt()
          .then(pending => {
            const startedAt = Date.parse(pending?.startedAt || '');
            return pending &&
              (pending.purpose ?? 'login') === 'login' &&
              Number.isFinite(startedAt) &&
              startedAt < abandonedAt
              ? deletePendingSocialAuthAttempt(pending)
              : false;
          })
          .catch(() => undefined);
      }),
    [currentSession, navigation],
  );

  const continueWith = async (provider: SocialProvider) => {
    if (
      authAttemptInFlightRef.current ||
      authFlow.phase !== 'ready' ||
      !authFlow.methods.providers.includes(provider)
    ) {
      return;
    }
    // Switching identities must pass through logout so push bindings, pending
    // writes and account-scoped caches are closed under their original owner.
    const existingSession = extractApiToken(currentSession)
      ? currentSession
      : peekSecureSession().session;
    if (extractApiToken(existingSession)) {
      dispatch(saveLoginData(existingSession));
      finishExistingSessionNavigation();
      return;
    }
    const intentGeneration = ++authIntentGenerationRef.current;
    const stillOwnsIntent = () =>
      intentGeneration === authIntentGenerationRef.current;
    authAttemptInFlightRef.current = true;
    sendAuthFlow({type: 'authorize', provider});
    try {
      const canPersistSession = await settleWithin(
        assertSecureSessionStorageAvailable().then(() => true),
        false,
        3_000,
      );
      if (!canPersistSession) {
        throw new Error('SESSION_STORAGE_UNAVAILABLE_DEADLINE');
      }
      // These two records preserve the interrupted guest journey, but neither
      // is part of the OAuth credential. A full AsyncStorage database must not
      // prevent a session that SecureStore can safely persist.
      const prepareGuestJourney = Promise.allSettled([
        savePendingLoginReturnTo(route.params?.returnTo),
        getCurrentAccountStorageScope().then(stageGuestAccountMigration),
      ]).then(() => undefined);
      await settleWithin(prepareGuestJourney, undefined, 600);
      const authenticatedSession = await signInWithSocialProvider(
        provider,
        authFlow.methods,
      );
      const committedSession = peekSecureSession().session;
      if (
        extractApiToken(committedSession) !==
        extractApiToken(authenticatedSession)
      ) {
        throw new Error('SESSION_STORAGE_UNAVAILABLE');
      }
      // Secure storage is the credential commit. Once it succeeds, Redux must
      // adopt that exact session even if the Login screen was replaced while
      // the provider callback was finishing. Dropping the local adoption here
      // left the app visibly logged out until the next cold start.
      dispatch(saveLoginData(committedSession));
      void resumeCompleteGuestAccountMigration().catch(() => undefined);
      // Redux changes the navigator key from guest to this account. Keep the
      // durable return until the new navigator has mounted and acknowledged
      // its exact envelope. The navigator is the only owner of the return;
      // resetting here as well caused a visible double navigation and could
      // retire the route before the account-owned stack mounted.
    } catch (error) {
      if (!stillOwnsIntent()) return;
      const code = socialAuthFailureCode(error);
      if (code === 'LOGIN_CANCELLED') {
        await clearPendingLoginReturnTo().catch(() => undefined);
      }
      if (code !== 'LOGIN_CANCELLED' && code !== 'LOGIN_RESUMING') {
        void reportClientError(new Error(code), {
          source: `auth.${provider}`,
        });
      }
      const message = socialAuthMessage(code);
      if (message) Alert.alert('تعذّر تسجيل الدخول', message);
    } finally {
      authAttemptInFlightRef.current = false;
      if (stillOwnsIntent()) {
        sendAuthFlow({type: 'authorization_finished'});
      }
    }
  };

  const retryAuthMethods = async () => {
    await loadAuthMethods().catch(() => undefined);
  };

  const enterFreePreview = async () => {
    authIntentGenerationRef.current += 1;
    authAttemptInFlightRef.current = false;
    sendAuthFlow({type: 'authorization_finished'});
    const existingSession = extractApiToken(currentSession)
      ? currentSession
      : peekSecureSession().session;
    if (extractApiToken(existingSession)) {
      dispatch(saveLoginData(existingSession));
      finishExistingSessionNavigation();
      return;
    }
    // Guest browsing never mutates the secure account store. Deleting a
    // keychain session here raced a late cold-start restore and could log out
    // an existing learner merely for backing out of the Login screen.
    const cleanupAbandonedLogin = Promise.allSettled([
      deletePendingSocialAuthAttempt(),
      clearPendingLoginReturnTo(),
    ]).then(() => undefined);
    await settleWithin(cleanupAbandonedLogin, undefined, 600);
    dispatch(LogOut());
    navigation.reset(loginReturnResetState(route.params?.returnTo, 'guest'));
  };

  const recommendedProvider = authMethods?.recommendedProvider;
  const recommendationText = authMethods?.recommendationText
    ? learnerFacingText(authMethods.recommendationText)
    : null;
  const providerOrder = [
    ...(recommendedProvider ? [recommendedProvider] : []),
    ...(authMethods?.providers ?? []),
  ].filter((value, index, list) => value && list.indexOf(value) === index);
  const orderedProviderIds = providerOrder.filter(provider =>
    authMethods?.providers.includes(provider),
  );

  return (
    <SocialAuthView
      failureCode={
        authFlow.phase === 'discovery_failed' ? authFlow.failureCode : undefined
      }
      loading={loading}
      methods={authMethods}
      onContinue={provider => void continueWith(provider)}
      onExplore={() => void enterFreePreview()}
      onOpenPrivacy={() => navigation.navigate('PrivacyPolicy')}
      onOpenTerms={() => navigation.navigate('TermsOfUse')}
      onRetry={() => void retryAuthMethods()}
      orderedProviderIds={orderedProviderIds}
      phase={authFlow.phase}
      recommendationText={recommendationText}
      recommendedProvider={recommendedProvider}
    />
  );
}
