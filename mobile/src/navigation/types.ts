import type {NavigatorScreenParams, RouteProp} from '@react-navigation/native';
import type {NativeStackNavigationProp} from '@react-navigation/native-stack';

export type CourseDetailsRouteParams = {
  courseId: string | number;
  openCodeRedemption?: boolean;
  openFullTrackUpgrade?: boolean;
  openPurchase?: boolean;
  purchasePlanCode?: string;
  purchaseCouponCode?: string;
  resumeAfterPreview?: boolean;
  resumeReelId?: string;
};

export type ReelsRouteParams = {
  courseId?: string | number;
  /** Memory-only, one-use handoff from a fresh course details read. */
  learningHandoffKey?: string;
  reelId?: string | number;
  lessonId?: string | number;
  projectId?: string | number;
  continueAfterReelId?: string | number;
  initialReelIndex?: number;
  initialPositionSeconds?: number;
  preview?: boolean;
  previewCount?: number;
  openCourseChatUpgrade?: boolean;
};

export type ProfileTab = 'portfolio' | 'certificates';

export const LOGIN_RETURN_TO_PARAMLESS_ROUTES = [
  'Wallet',
  'MyCorner',
  'Settings',
  'EditAccount',
  'DeviceSessions',
  'Notifications',
  'SavedLibrary',
] as const;

export type LoginReturnToParamlessRoute =
  (typeof LOGIN_RETURN_TO_PARAMLESS_ROUTES)[number];

export type LoginReturnTo =
  | {name: 'CourseCertificate'; params: {courseId: string}}
  | {
      name: 'CourseDetails';
      params: Omit<CourseDetailsRouteParams, 'courseId'> & {courseId: string};
    }
  | {
      name: 'Reels';
      params: {
        courseId: string;
        reelId?: string;
        lessonId?: string;
        projectId?: string;
        preview?: boolean;
        previewCount?: number;
        openCourseChatUpgrade?: boolean;
      };
    }
  | {
      name: 'Profile';
      params?: {tab: ProfileTab};
    }
  | {
      name: LoginReturnToParamlessRoute;
      params?: never;
    };

export type LoginRouteParams = {
  returnTo?: LoginReturnTo;
};

/** The four bottom-navigation destinations, in tab-bar order. */
export type RootTabParamList = {
  Home: undefined;
  MyCorner: undefined;
  Wallet: {returnTo?: LoginReturnTo} | undefined;
  Profile:
    | {
        tab?: ProfileTab;
        /** Passed course project to add to the portfolio (one-shot). */
        addPortfolioProjectId?: string;
      }
    | undefined;
};

export type RootTabName = keyof RootTabParamList;

export const ROOT_TAB_NAMES = [
  'Home',
  'MyCorner',
  'Wallet',
  'Profile',
] as const satisfies readonly RootTabName[];

export type RootStackParamList = {
  /** Bottom tabs; every other screen is pushed above them. */
  Tabs: NavigatorScreenParams<RootTabParamList> | undefined;
  Login: LoginRouteParams | undefined;
  EditAccount: undefined;
  Feedback: {sourceScreen?: string; caseId?: string} | undefined;
  Reels: ReelsRouteParams;
  CourseDetails: CourseDetailsRouteParams;
  CourseCertificate: {courseId: string};
  AboutUs: undefined;
  PrivacyPolicy: undefined;
  TermsOfUse: undefined;
  ReturnsPolicy: undefined;
  Notifications: undefined;
  Settings: undefined;
  DeviceSessions: undefined;
  /** Optional list to open, e.g. from a ركني collection card. */
  SavedLibrary: {folderId?: string} | undefined;
};

/**
 * Every screen a flat destination (deep link, login return, checkout return)
 * can name. Tabs are named directly; `rootRoutes` nests them under `Tabs`.
 */
export type AppParamList = Omit<RootStackParamList, 'Tabs'> & RootTabParamList;
export type AppRouteName = keyof AppParamList;

export type RootNavigation = NativeStackNavigationProp<RootStackParamList>;
export type RootRoute<RouteName extends AppRouteName> = RouteProp<
  AppParamList,
  RouteName
>;
