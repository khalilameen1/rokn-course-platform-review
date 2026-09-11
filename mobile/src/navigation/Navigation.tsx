import {NavigationContainer} from '@react-navigation/native';
import {createNativeStackNavigator} from '@react-navigation/native-stack';

import React from 'react';
import {Image, StyleSheet, View} from 'react-native';

import {navigationRef} from './RootNavigationHelper';

import Reels from '../screens/Reels';
import Home from '../screens/Home';
import Login from '../screens/Login';
import CourseDetails from '../screens/CourseDetails';
import MyCorner from '../screens/MyCorner';
import Wallet from '../screens/Wallet';
import Profile from '../screens/Profile';
import Settings from '../screens/Settings';
import AboutUs from '../screens/Informations/AboutUs';
import PrivacyPolicy from '../screens/Informations/PrivacyPolicy';
import TermsOfUse from '../screens/Informations/TermsOfUse';
import ReturnsPolicy from '../screens/Informations/ReturnsPolicy';
import Notifications from '../screens/Notifications';
import EditAccount from '../screens/EditAccount';
import Feedback from '../screens/Feedback';
import DeviceSessions from '../screens/DeviceSessions';
import type {RootStackParamList} from './types';
import {
  flushPendingNotificationNavigation,
  setNotificationNavigationReady,
} from '../services/pushNotifications';
import {useReducedMotion} from '../hooks/useReducedMotion';
import {
  flushLateInitialDestination,
  markRoknNavigationReady,
  roknLinking,
} from './roknLinking';
import {useInterruptedJourneyRestore} from './useInterruptedJourneyRestore';
import {Palette} from '../constants/designSystem';
import {AuthenticatedScreenBoundary} from './AuthenticatedScreenBoundary';
import {extractApiToken} from '../constants/helpers';
import {useSelector} from 'react-redux';
import type {RootState} from '../store/store';
const Stack = createNativeStackNavigator<RootStackParamList>();

const NavigationFallback = () => (
  <View style={styles.fallback}>
    <Image
      accessibilityElementsHidden
      importantForAccessibility="no"
      source={require('../assets/images/logo.png')}
      style={styles.fallbackLogo}
    />
  </View>
);

const Stacks = ({sessionReady}: {sessionReady: boolean}) => {
  const reducedMotion = useReducedMotion();
  const authenticated = useSelector((state: RootState) =>
    Boolean(extractApiToken(state.auth.userData)),
  );

  return (
    <Stack.Navigator
      screenOptions={{
        animation: reducedMotion ? 'none' : 'default',
        headerShown: false,
      }}
      initialRouteName="Home">
      <Stack.Screen
        name="Login"
        component={Login}
        options={{
          presentation: 'transparentModal',
          animation: reducedMotion ? 'none' : 'fade',
          contentStyle: {backgroundColor: 'transparent'},
          gestureEnabled: false,
        }}
      />
      <Stack.Screen name="Feedback" component={Feedback} />
      <Stack.Screen name="Home" component={Home} />
      <Stack.Screen name="Reels" component={Reels} />
      <Stack.Screen name="CourseDetails" component={CourseDetails} />
      <Stack.Screen name="AboutUs" component={AboutUs} />
      <Stack.Screen name="PrivacyPolicy" component={PrivacyPolicy} />
      <Stack.Screen name="TermsOfUse" component={TermsOfUse} />
      <Stack.Screen name="ReturnsPolicy" component={ReturnsPolicy} />
      <Stack.Screen name="Settings" component={Settings} />
      <Stack.Group
        screenLayout={({children, navigation, route}) => (
          <AuthenticatedScreenBoundary
            authenticated={authenticated}
            navigation={navigation}
            route={route}
            sessionReady={sessionReady}>
            {children}
          </AuthenticatedScreenBoundary>
        )}>
        <Stack.Screen name="EditAccount" component={EditAccount} />
        <Stack.Screen name="MyCorner" component={MyCorner} />
        <Stack.Screen name="Wallet" component={Wallet} />
        <Stack.Screen name="Profile" component={Profile} />
        <Stack.Screen name="Notifications" component={Notifications} />
        <Stack.Screen name="DeviceSessions" component={DeviceSessions} />
      </Stack.Group>
    </Stack.Navigator>
  );
};

const Navigation = ({sessionReady}: {sessionReady: boolean}) => {
  const {run: restoreInterruptedJourney, sessionKey} =
    useInterruptedJourneyRestore();

  return (
    <NavigationContainer
      fallback={<NavigationFallback />}
      linking={roknLinking}
      onReady={() => {
        void restoreInterruptedJourney().finally(() => {
          markRoknNavigationReady();
          flushLateInitialDestination();
          setNotificationNavigationReady(true);
          void flushPendingNotificationNavigation();
        });
      }}
      ref={navigationRef}>
      <Stacks key={sessionKey} sessionReady={sessionReady} />
    </NavigationContainer>
  );
};

const styles = StyleSheet.create({
  fallback: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.canvas,
  },
  fallbackLogo: {width: 104, height: 42, resizeMode: 'contain'},
});

export default Navigation;
