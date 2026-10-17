import {
  createBottomTabNavigator,
  type BottomTabBarProps,
} from '@react-navigation/bottom-tabs';
import {NavigationContainer} from '@react-navigation/native';
import {createNativeStackNavigator} from '@react-navigation/native-stack';

import React from 'react';
import {StyleSheet, View} from 'react-native';

import {navigationRef} from './RootNavigationHelper';

import Reels from '../screens/Reels';
import Home from '../screens/Home';
import Login from '../screens/Login';
import CourseDetails from '../screens/CourseDetails';
import CourseCertificate from '../screens/CourseCertificate';
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
import SavedLibrary from '../screens/SavedLibrary';
import type {
  RootNavigation,
  RootStackParamList,
  RootTabParamList,
} from './types';
import TabBar from '../components/TabBar';
import {
  flushPendingNotificationNavigation,
  setNotificationNavigationReady,
} from '../services/pushNotificationNavigation';
import {useReducedMotion} from '../hooks/useReducedMotion';
import {
  flushLateInitialDestination,
  markRoknNavigationReady,
  roknLinking,
} from './roknLinking';
import {useInterruptedJourneyRestore} from './useInterruptedJourneyRestore';
import {AuthenticatedScreenBoundary} from './AuthenticatedScreenBoundary';
import {extractApiToken} from '../constants/helpers';
import {useSelector} from 'react-redux';
import type {RootState} from '../store/store';
import {useStartupExperience} from '../screens/appInitializer/StartupExperience';
import {openGuestLogin} from './journeyNavigation';
const Stack = createNativeStackNavigator<RootStackParamList>();
const Tab = createBottomTabNavigator<RootTabParamList>();

// The native splash already owns the brand while initial linking resolves.
const NavigationFallback = () => <View style={styles.navigationFallback} />;
const styles = StyleSheet.create({
  navigationFallback: {flex: 1, backgroundColor: '#0B1628'},
});

const renderTabBar = (props: BottomTabBarProps) => <TabBar {...props} />;

type AccountGate = {authenticated: boolean; sessionReady: boolean};

/**
 * Bottom navigation keeps every visited tab mounted (lazy until first visit),
 * like Instagram, YouTube and React Navigation's bottom-tab navigator: a tab
 * switch keeps scroll, state and data instead of refetching. Switches are
 * instant (`animation: 'none'`), and Android Back from another tab returns to
 * Home before leaving the app.
 */
const RootTabs = ({
  authenticated,
  navigation,
  sessionReady,
}: AccountGate & {navigation: RootNavigation}) => (
  <Tab.Navigator
    backBehavior="initialRoute"
    initialRouteName="Home"
    screenListeners={({route}) => ({
      // A guest's private tab opens the login sheet over the current tab and
      // returns to the chosen tab after login, without mounting its content.
      tabPress: event => {
        if (authenticated || route.name === 'Home') return;
        event.preventDefault();
        openGuestLogin(navigation, {name: route.name});
      },
    })}
    screenOptions={{animation: 'none', headerShown: false, lazy: true}}
    tabBar={renderTabBar}>
    <Tab.Screen name="Home" component={Home} />
    <Tab.Group
      screenLayout={({children, route}) => (
        <AuthenticatedScreenBoundary
          authenticated={authenticated}
          navigation={navigation}
          route={route}
          sessionReady={sessionReady}>
          {children}
        </AuthenticatedScreenBoundary>
      )}>
      <Tab.Screen name="MyCorner" component={MyCorner} />
      <Tab.Screen name="Wallet" component={Wallet} />
      <Tab.Screen name="Profile" component={Profile} />
    </Tab.Group>
  </Tab.Navigator>
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
        statusBarStyle: 'light',
      }}
      initialRouteName="Tabs">
      <Stack.Screen name="Tabs">
        {({navigation}) => (
          <RootTabs
            authenticated={authenticated}
            navigation={navigation}
            sessionReady={sessionReady}
          />
        )}
      </Stack.Screen>
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
        <Stack.Screen name="CourseCertificate" component={CourseCertificate} />
        <Stack.Screen name="Notifications" component={Notifications} />
        <Stack.Screen name="DeviceSessions" component={DeviceSessions} />
        <Stack.Screen name="SavedLibrary" component={SavedLibrary} />
      </Stack.Group>
    </Stack.Navigator>
  );
};

const Navigation = ({sessionReady}: {sessionReady: boolean}) => {
  const startup = useStartupExperience();
  const {run: restoreInterruptedJourney, sessionKey} =
    useInterruptedJourneyRestore();

  return (
    <NavigationContainer
      fallback={<NavigationFallback />}
      linking={roknLinking}
      onReady={() => {
        void restoreInterruptedJourney().finally(() => {
          markRoknNavigationReady();
          const initialRoute = navigationRef.getCurrentRoute()?.name;
          if (initialRoute && initialRoute !== 'Home') {
            startup?.initialContentReady();
          }
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

export default Navigation;
