import React, {forwardRef} from 'react';
import {Image, type ImageProps} from 'react-native';

/** Fresco owns Android networking/cache and decodes to the measured view size.
 * Preserve source headers, callbacks, accessibility and native iOS behavior. */
export const RasterImage = forwardRef<
  React.ElementRef<typeof Image>,
  ImageProps
>((props, ref) => <Image {...props} ref={ref} resizeMethod="resize" />);
RasterImage.displayName = 'RasterImage';
