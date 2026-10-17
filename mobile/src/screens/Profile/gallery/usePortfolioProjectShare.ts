import {useCallback} from 'react';
import {Alert} from 'react-native';

import {shareOnce} from '../../../services/systemActions';
import {portfolioProjectShareUrl, type Project} from './portfolioModel';

/**
 * "Share project" on one published, visible project (Behance/Dribbble share
 * a single shot or project page, not only the whole profile).
 */
export const usePortfolioProjectShare = ({
  mountedRef,
  portfolioPublicUrl,
  selected,
}: {
  mountedRef: React.MutableRefObject<boolean>;
  portfolioPublicUrl?: string;
  selected: Project | null;
}) => {
  const shareUrl = selected
    ? portfolioProjectShareUrl(portfolioPublicUrl, selected)
    : null;
  const share = useCallback(async () => {
    if (!selected || !shareUrl) return;
    try {
      await shareOnce(`portfolio-project:${selected.id}`, {
        title: selected.title,
        message: `شوف مشروعي ${selected.title} على ركن\n${shareUrl}`,
        url: shareUrl,
      });
    } catch {
      if (mountedRef.current) Alert.alert('تعذّرت المشاركة', 'جرب تاني');
    }
  }, [mountedRef, selected, shareUrl]);
  return shareUrl ? share : undefined;
};
