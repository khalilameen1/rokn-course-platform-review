import {appleSocialAuthAvailable} from './socialAuthApple';
import {getSocialAuthMethods as discoverMethods} from './socialAuthMethods';
import type {SocialAuthMethods, SocialProvider} from './socialAuthTypes';

export const getDeviceSocialAuthMethods =
  async (): Promise<SocialAuthMethods> => {
    const methods = await discoverMethods();
    if (!methods.providers.includes('apple')) return methods;
    if (await appleSocialAuthAvailable()) return methods;

    const providers: SocialProvider[] = methods.providers.filter(
      provider => provider !== 'apple',
    );
    const recommendedProvider =
      methods.recommendedProvider &&
      providers.includes(methods.recommendedProvider)
        ? methods.recommendedProvider
        : providers[0] ?? null;
    return {
      ...methods,
      providers,
      recommendedProvider,
      recommendationText:
        recommendedProvider === methods.recommendedProvider
          ? methods.recommendationText
          : null,
    };
  };
