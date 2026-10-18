import type {PortfolioProfile} from '../../../services/roknApi';

export type GalleryProps = {
  /** Current session owner (`sessionIdentityKey`); scopes the list query. */
  identityKey: string;
  onSubscriptions?: (hasSubscription: boolean) => void;
  onSharePortfolio?: () => void | Promise<void>;
  onShareablePortfolioChange?: (available: boolean) => void;
  /** Live public portfolio link; each visible project shares its own page. */
  portfolioPublicUrl?: string;
  onPortfolioProfileChange?: (profile: PortfolioProfile) => void;
  /** A passed course project to add, from the project screen. */
  addFromProjectId?: string;
  onAddFromProjectHandled?: () => void;
};
