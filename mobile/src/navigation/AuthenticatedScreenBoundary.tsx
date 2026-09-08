import React, {useEffect} from 'react';
import {StyleSheet, View} from 'react-native';

import {Palette} from '../constants/designSystem';
import {safeLoginReturnToFromRoute} from './authReturn';
import {goBackOrHome} from './RootNavigationHelper';
import {promptGuestLogin} from './journeyNavigation';
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
    const prompted = promptGuestLogin(navigation, returnTo, {
      onCancel: () => goBackOrHome(navigation),
      onLogin: () =>
        navigation.reset({
          index: 1,
          routes: [
            {name: 'Home'},
            {name: 'Login', params: returnTo ? {returnTo} : undefined},
          ],
        }),
    });
    // Another prompt can already own the native alert. Never leave a private
    // route blank underneath it if this destination could not claim the gate.
    if (!prompted) goBackOrHome(navigation);
  }, [authenticated, navigation, route, sessionReady]);

  if (!sessionReady || !authenticated)
    return <View style={styles.closed} />;
  return children;
};

const styles = StyleSheet.create({
  closed: {flex: 1, backgroundColor: Palette.canvas},
});
