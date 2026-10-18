import React, {type ReactNode} from 'react';
import {useSelector} from 'react-redux';
import {sessionIdentityKey} from '../../constants/helpers';
import type {RootState} from '../../store/store';
import {RoknQueryProvider} from './RoknQueryProvider';

/** index.js wiring: the owner comes from the same Redux session as screens. */
export const AppQueryProvider = ({children}: {children: ReactNode}) => {
  const identityKey = useSelector((state: RootState) =>
    sessionIdentityKey(state.auth.userData),
  );
  return (
    <RoknQueryProvider identityKey={identityKey}>{children}</RoknQueryProvider>
  );
};
