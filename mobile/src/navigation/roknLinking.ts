import {
  getStateFromPath as getNavigationStateFromPath,
  type LinkingOptions,
} from '@react-navigation/native';
import {Linking} from 'react-native';

import {openRoknDestination} from './RootNavigationHelper';
import {
  parseRoknDestination,
  resolveInitialUrlWithinDeadline,
  roknDestinationKey,
  type RoknDestination,
} from './deepLinks';
import type {RootStackParamList} from './types';

let lastDeliveredDestination = '';
let lastDeliveredAt = 0;
let lateInitialDestination: RoknDestination | null = null;
let navigationReady = false;
const WARM_LINK_DEDUPE_MS = 1_500;
let initialAppUrlFlight: Promise<string | null> | null = null;

/** One native cold-start read shared by navigation and OAuth recovery. */
export const getInitialAppUrl = () => {
  if (!initialAppUrlFlight) {
    initialAppUrlFlight = Linking.getInitialURL().catch(() => null);
  }
  return initialAppUrlFlight;
};

const rememberDeliveredDestination = (destination: RoknDestination) => {
  lastDeliveredDestination = roknDestinationKey(destination);
  lastDeliveredAt = Date.now();
};

const deliverLateInitialUrl = (url: string | null) => {
  const destination = parseRoknDestination(url);
  if (!destination) return;
  rememberDeliveredDestination(destination);
  if (!navigationReady || !openRoknDestination(destination)) {
    lateInitialDestination = destination;
  }
};

export const resetRoknLinking = () => {
  navigationReady = false;
  lateInitialDestination = null;
};

export const isRoknNavigationReady = () => navigationReady;

export const markRoknNavigationReady = () => {
  navigationReady = true;
};

export const flushLateInitialDestination = () => {
  if (!navigationReady || !lateInitialDestination) return false;
  const destination = lateInitialDestination;
  if (!openRoknDestination(destination)) return false;
  lateInitialDestination = null;
  return true;
};

export const roknLinking: LinkingOptions<RootStackParamList> = {
  prefixes: ['rokn://', 'https://rokn.app', 'https://www.rokn.app'],
  async getInitialURL() {
    const url = await resolveInitialUrlWithinDeadline(
      getInitialAppUrl(),
      deliverLateInitialUrl,
    );
    const destination = parseRoknDestination(url);
    if (destination) rememberDeliveredDestination(destination);
    return url;
  },
  subscribe(listener) {
    const subscription = Linking.addEventListener('url', ({url}) => {
      const destination = parseRoknDestination(url);
      if (!destination) return;
      const destinationKey = roknDestinationKey(destination);
      const now = Date.now();

      if (openRoknDestination(destination)) {
        lastDeliveredDestination = destinationKey;
        lastDeliveredAt = now;
        return;
      }
      if (
        destinationKey === lastDeliveredDestination &&
        now - lastDeliveredAt >= 0 &&
        now - lastDeliveredAt < WARM_LINK_DEDUPE_MS
      ) {
        return;
      }
      lastDeliveredDestination = destinationKey;
      lastDeliveredAt = now;
      listener(url);
    });
    return () => subscription.remove();
  },
  filter: url => parseRoknDestination(url) !== null,
  getStateFromPath(path, options) {
    const destination = parseRoknDestination(path);
    if (!destination) return undefined;
    const normalizedPath =
      destination.name === 'Home'
        ? 'home'
        : destination.name === 'Profile'
        ? `profile${
            destination.params?.tab ? `/${destination.params.tab}` : ''
          }`
        : destination.name === 'Wallet'
        ? 'wallet'
        : destination.name === 'Feedback'
        ? `support/${destination.params.caseId}`
        : destination.name === 'CourseDetails'
        ? `course/${destination.params.courseId}`
        : destination.params.projectId
        ? `course/${destination.params.courseId}/watch?projectId=${destination.params.projectId}`
        : destination.params.lessonId
        ? `course/${destination.params.courseId}/watch?lessonId=${destination.params.lessonId}`
        : `course/${destination.params.courseId}/watch${
            destination.params.reelId ? `/${destination.params.reelId}` : ''
          }`;
    const state = getNavigationStateFromPath(normalizedPath, options);
    if (!state || destination.name === 'Home' || !state.routes?.length) {
      return state;
    }
    return {
      ...state,
      index: state.routes.length,
      routes: [{name: 'Home' as const}, ...state.routes],
    };
  },
  config: {
    screens: {
      Home: 'home',
      CourseDetails: 'course/:courseId',
      Reels: 'course/:courseId/watch/:reelId?',
      Profile: 'profile/:tab?',
      Wallet: 'wallet',
      Feedback: 'support/:caseId',
    },
  },
};
