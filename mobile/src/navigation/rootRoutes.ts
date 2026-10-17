import type {NavigatorScreenParams} from '@react-navigation/native';

import {
  ROOT_TAB_NAMES,
  type AppRouteName,
  type RootNavigation,
  type RootTabName,
  type RootTabParamList,
} from './types';

/**
 * The root is a native stack whose first screen, `Tabs`, is the bottom-tab
 * navigator (Home, MyCorner, Wallet, Profile). Intents across the app still
 * name a flat destination such as `{name: 'Profile', params}`; these helpers
 * are the one place that turns such a destination into explicit nested
 * navigation (React Navigation 7 "Navigating to a screen in a nested
 * navigator") instead of enabling the legacy `navigationInChildEnabled`.
 */

export const isRootTabName = (name: unknown): name is RootTabName =>
  (ROOT_TAB_NAMES as readonly unknown[]).includes(name);

type TabsRoute = {
  name: 'Tabs';
  state?: {
    index: number;
    routes: Array<{name: RootTabName; params?: object}>;
  };
};
type StackRoute = {name: Exclude<AppRouteName, RootTabName>; params?: object};
export type RootStackRoute = TabsRoute | StackRoute;

const withParams = <Name extends string>(name: Name, params?: object) =>
  params === undefined ? {name} : {name, params};

/**
 * The tab navigator focused on `tab`. Its router fills in the other tabs and,
 * with `backBehavior="initialRoute"`, keeps Home as the Back target.
 */
export const tabsRoute = (
  tab: RootTabName = 'Home',
  params?: object,
): TabsRoute =>
  tab === 'Home' && params === undefined
    ? {name: 'Tabs'}
    : {name: 'Tabs', state: {index: 0, routes: [withParams(tab, params)]}};

/** Root-stack routes for a flat destination: tabs first, any screen above. */
export const rootRoutesFor = (destination: {
  name: AppRouteName;
  params?: object;
}): RootStackRoute[] =>
  isRootTabName(destination.name)
    ? [tabsRoute(destination.name, destination.params)]
    : [
        {name: 'Tabs'},
        withParams(
          destination.name as Exclude<AppRouteName, RootTabName>,
          destination.params,
        ),
      ];

/**
 * A root reset for `destination`, optionally with one more screen above it
 * (for example the Login sheet over a private destination).
 */
export const rootResetState = (
  destination: {name: AppRouteName; params?: object},
  above?: {name: Exclude<AppRouteName, RootTabName>; params?: object},
) => {
  const routes: RootStackRoute[] = [
    ...rootRoutesFor(destination),
    ...(above ? [withParams(above.name, above.params)] : []),
  ];
  return {index: routes.length - 1, routes};
};

/**
 * Show a bottom tab from anywhere. From a pushed screen this pops back to the
 * one tab navigator (never a second copy of it) and focuses the tab, keeping
 * every visited tab mounted.
 */
export const openRootTab = <TabName extends RootTabName>(
  navigation: Pick<RootNavigation, 'navigate'>,
  tab: TabName,
  ...[params]: undefined extends RootTabParamList[TabName]
    ? [params?: RootTabParamList[TabName]]
    : [params: RootTabParamList[TabName]]
) => {
  const screen = {
    screen: tab,
    params,
  } as NavigatorScreenParams<RootTabParamList>;
  navigation.navigate('Tabs', screen, {pop: true});
};
