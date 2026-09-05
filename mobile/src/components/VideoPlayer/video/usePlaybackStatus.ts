import {useCallback, useState, type SetStateAction} from 'react';
import type {PlaybackFailure} from './policy';

type PlaybackStatus = {
  error: boolean;
  failureKind: PlaybackFailure;
  isBuffering: boolean;
  isLoaded: boolean;
  recoveryMessage: string;
};

const initialStatus: PlaybackStatus = {
  error: false,
  failureKind: 'source',
  isBuffering: true,
  isLoaded: false,
  recoveryMessage: '',
};

const resolveAction = <T>(action: SetStateAction<T>, current: T): T =>
  typeof action === 'function' ? (action as (value: T) => T)(current) : action;

export const usePlaybackStatus = () => {
  const [status, setStatus] = useState(initialStatus);

  const updateField = useCallback(
    <TKey extends keyof PlaybackStatus>(
      field: TKey,
      action: SetStateAction<PlaybackStatus[TKey]>,
    ) =>
      setStatus(current => ({
        ...current,
        [field]: resolveAction(action, current[field]),
      })),
    [],
  );

  const setError = useCallback(
    (value: SetStateAction<boolean>) => updateField('error', value),
    [updateField],
  );
  const setFailureKind = useCallback(
    (value: SetStateAction<PlaybackFailure>) =>
      updateField('failureKind', value),
    [updateField],
  );
  const setIsBuffering = useCallback(
    (value: SetStateAction<boolean>) => updateField('isBuffering', value),
    [updateField],
  );
  const setIsLoaded = useCallback(
    (value: SetStateAction<boolean>) => updateField('isLoaded', value),
    [updateField],
  );
  const setRecoveryMessage = useCallback(
    (value: SetStateAction<string>) => updateField('recoveryMessage', value),
    [updateField],
  );

  const resetStatus = useCallback(
    (recoveryMessage = '') => setStatus({...initialStatus, recoveryMessage}),
    [],
  );

  const failPlayback = useCallback((failureKind: PlaybackFailure) => {
    setStatus(current => ({
      ...current,
      error: true,
      failureKind,
      isBuffering: false,
      recoveryMessage: '',
    }));
  }, []);

  const markStatusHealthy = useCallback(() => {
    setStatus(current =>
      !current.error &&
      !current.isBuffering &&
      current.isLoaded &&
      current.recoveryMessage === ''
        ? current
        : {
            ...current,
            error: false,
            isBuffering: false,
            isLoaded: true,
            recoveryMessage: '',
          },
    );
  }, []);

  return {
    ...status,
    failPlayback,
    markStatusHealthy,
    resetStatus,
    setError,
    setFailureKind,
    setIsBuffering,
    setIsLoaded,
    setRecoveryMessage,
  };
};
