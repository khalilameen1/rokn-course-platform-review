import React, {useEffect} from 'react';
import {StyleSheet, View} from 'react-native';

import {Palette} from '../constants/designSystem';
import {safeLoginReturnToFromRoute} from './authReturn';
import type {RootNavigation, RootStackParamList} from './types';

type RouteSnapshot = {
  key: string;
  name: keyof RootStackParamList;
  params?: object;
};

type Props = {
  authenticated: boolean;
  children: React.ReactElement;
  navigation: Pick<
    RootNavigation,
    'canGoBack' | 'getState' | 'goBack' | 'navigate' | 'reset'
  >;
  route: RouteSnapshot;
  sessionReady: boolean;
};

/** Prevent account-owned screens from mounting before the session boundary. */
export const AuthenticatedScreenBoundary = ({
  authenticated,
  children,
  navigation,
  route,
  sessionReady,
}: Props) => {
  useEffect(() => {
    if (!sessionReady || authenticated) return;
    const returnTo = safeLoginReturnToFromRoute(route);
    // A private deep link has no guest page to show. Present the same login
    // sheet above Home without mounting the private screen or an extra alert.
    navigation.reset({
      index: 1,
      routes: [
        {name: 'Home'},
        {name: 'Login', params: returnTo ? {returnTo} : undefined},
      ],
    });
  }, [authenticated, navigation, route, sessionReady]);

  if (!sessionReady || !authenticated)
    return <View style={styles.closed} />;
  return children;
};

const styles = StyleSheet.create({
  closed: {flex: 1, backgroundColor: Palette.canvas},
});
