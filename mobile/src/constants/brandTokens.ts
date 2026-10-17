/**
 * Rokn's cross-surface colour source of truth.
 *
 * Components consume Palette/Colors rather than repeating these values. Native
 * integrations that cannot depend on the UI layer (for example notification
 * channels) may consume BrandColors directly.
 */
export const BrandColors = {
  canvas: '#070A10',
  canvasSoft: '#0B1018',
  surface: '#111620',
  surfaceRaised: '#171D29',
  surfacePressed: '#1D2533',
  line: '#252C38',
  lineSoft: 'rgba(255,255,255,0.07)',
  primary: '#2C69DB',
  primaryPressed: '#245CC7',
  primarySoft: 'rgba(52,120,246,0.14)',
  /**
   * Same hue as `primary`, lightened for small text and links on Rokn's dark
   * surfaces (WCAG 2.2 AA 1.4.3: 4.5:1). `primary` stays the fill colour.
   * Contrast: canvas 5.90, canvasSoft 5.68, surface 5.40, surfaceRaised 5.03,
   * surfacePressed 4.59.
   */
  primaryText: '#5C8BE3',
  // Light blue tints already used across screens, consolidated from
  // near-duplicate literals (CIEDE2000 between merged pairs <= 1.3).
  primaryTint: '#76A9FF',
  primaryTintText: '#8BB5FF',
  primaryTintSoftText: '#9ABFFF',
  primaryTintSelected: '#A9C9FF',
  coin: '#D8A63C',
  // Light gold for text/strokes on dark surfaces (merged #E8C66F/#E6C465).
  coinText: '#E9C66F',
  coinSoft: 'rgba(216,166,60,0.13)',
  success: '#48B98A',
  danger: '#F06469',
  text: '#F7F9FC',
  textMuted: '#9BA6B8',
  textFaint: '#768297',
  /**
   * `textFaint` measures 4.35:1 on surfaceRaised and 3.96:1 on surfacePressed.
   * Use this tint for faint text on raised/pressed surfaces (5.04 / 4.59).
   */
  textFaintOnRaised: '#828DA0',
  overlay: 'rgba(3,5,9,0.76)',
} as const;
