import type {PortfolioProfile} from '../../services/api/portfolioContract';

const MAX_PORTFOLIO_MEDIA = 12;

type PortfolioMediaState = {
  media: Array<{id: string; status?: 'ready' | 'processing' | 'failed'}>;
  uploadedMediaCount?: number;
  expectedMediaCount?: number;
  shareReady: boolean;
};

export const portfolioMediaCount = (
  item: Pick<PortfolioMediaState, 'media' | 'uploadedMediaCount'>,
) => Math.max(item.media.length, Math.max(0, item.uploadedMediaCount || 0));

export const portfolioMediaSlots = (
  item: Pick<PortfolioMediaState, 'media' | 'uploadedMediaCount'>,
) => Math.max(0, MAX_PORTFOLIO_MEDIA - portfolioMediaCount(item));

export type PortfolioAction = 'upload' | 'complete' | 'share';

export const portfolioAction = (
  item: Pick<
    PortfolioMediaState,
    'media' | 'uploadedMediaCount' | 'shareReady'
  >,
): PortfolioAction => {
  const hasUploadedWork = portfolioMediaCount(item) > 0;
  if (item.shareReady && hasUploadedWork) return 'share';
  return hasUploadedWork ? 'complete' : 'upload';
};

export const isShareablePortfolioItem = (
  item: Pick<
    PortfolioMediaState,
    'media' | 'uploadedMediaCount' | 'shareReady'
  >,
) => portfolioAction(item) === 'share';

export const portfolioPublicationDisposition = (
  item: PortfolioMediaState,
): 'published' | 'retry' | 'incomplete' => {
  if (isShareablePortfolioItem(item)) return 'published';
  return item.media.some(media => media.status === 'processing')
    ? 'retry'
    : 'incomplete';
};

/**
 * An upload can finish just before the app is killed, leaving no media outbox
 * entry but a server draft that still needs its idempotent finalize call.
 */
export const portfolioNeedsPublicationRecovery = (item: PortfolioMediaState) =>
  !item.shareReady && portfolioMediaCount(item) > 0;

export const portfolioLifecycleAfterMediaRemoval = (
  item: PortfolioMediaState,
  remainingVisibleMediaCount: number,
): Pick<PortfolioMediaState, 'uploadedMediaCount' | 'shareReady'> => {
  const uploadedMediaCount = Math.max(
    0,
    remainingVisibleMediaCount,
    portfolioMediaCount(item) - 1,
  );

  return {
    uploadedMediaCount,
    shareReady: uploadedMediaCount > 0 && item.shareReady,
  };
};

/** Owner-facing sharing state; the live version stays shareable during review. */
export const portfolioSharingNotice = (profile: PortfolioProfile | null) => {
  switch (profile?.sharingStatus) {
    case 'pending':
      return profile.hasUnpublishedChanges
        ? 'تعديلاتك قيد المراجعة والنسخة المنشورة شغالة'
        : 'أعمالك قيد المراجعة\nسيظهر رابط المشاركة بعد الموافقة';
    case 'rejected': {
      const notice = profile.sharingRejectionReason
        ? `عدّل أعمالك لإتاحتها للمشاركة\n${profile.sharingRejectionReason}`
        : 'عدّل أعمالك لإتاحتها للمشاركة';
      return profile.published ? `${notice}\nالنسخة المنشورة شغالة` : notice;
    }
    case 'suspended':
      return 'المشاركة موقوفة مؤقتًا\nأعمالك محفوظة ويمكنك التواصل مع الدعم';
    default:
      return '';
  }
};
