import React, {FC, useEffect} from 'react';
import {useSelector} from 'react-redux';
import Navigation from '../navigation/Navigation';
import AppUpdateGate from '../components/AppUpdateGate';
import type {RootState} from '../store/store';
import {cleanupOldFiles} from '../utils/fileCache';
import {useSessionBootstrap} from './appInitializer/useSessionBootstrap';
import {useAppUpdateNotice} from './appInitializer/useAppUpdateNotice';
import {useAppRuntime} from './appInitializer/useAppRuntime';
import {StartupExperience} from './appInitializer/StartupExperience';

const AppInitializer: FC = () => {
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const {sessionReady, adoptAuthenticatedSession, resumePendingAuthentication} =
    useSessionBootstrap();
  const update = useAppUpdateNotice();

  useEffect(() => {
    void cleanupOldFiles().catch(() => undefined);
  }, []);

  useAppRuntime({
    sessionReady,
    storedUser,
    refreshUpdateNotice: update.refresh,
    adoptAuthenticatedSession,
    resumePendingAuthentication,
  });

  return (
    <StartupExperience sessionReady={sessionReady}>
      <Navigation sessionReady={sessionReady} />
      <AppUpdateGate notice={update.notice} onDismiss={update.dismiss} />
    </StartupExperience>
  );
};

export default AppInitializer;
