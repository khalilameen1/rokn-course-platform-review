import React from 'react';
import {PortfolioGalleryView} from './gallery/PortfolioGalleryView';
import {
  type GalleryProps,
  usePortfolioGalleryController,
} from './gallery/usePortfolioGalleryController';

export type {GalleryProps};

export default function Gallery(props: GalleryProps = {}) {
  const controller = usePortfolioGalleryController(props);
  return <PortfolioGalleryView controller={controller} />;
}
