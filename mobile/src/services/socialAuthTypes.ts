export type SocialProvider = 'google' | 'tiktok' | 'facebook' | 'apple';

export type SocialAuthMethods = {
  providers: SocialProvider[];
  authorizationUrls: Partial<Record<SocialProvider, string>>;
  authorizationApiUrl: string;
  welcomeBonus: number | null;
  recommendedProvider: SocialProvider | null;
  recommendationText: string | null;
};

export type SocialAuthSession = Record<string, unknown> & {
  api_token: string;
  user: Record<string, unknown> & {
    name: string;
    email: string | null;
    social_provider: SocialProvider;
  };
};
