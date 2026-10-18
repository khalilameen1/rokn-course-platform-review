/**
 * One Egyptian vocabulary for learner-facing retry and failure copy
 * (ARCHITECTURE.md M5). Each phrase is wording the app already shipped:
 * - retry: src/screens/home/useHomeSearch.ts, src/screens/reels/usePlaybackPreferences.ts
 * - networkRetry: src/components/VideoPlayer/feedSideBar/useSavedFolderPicker.ts,
 *   src/screens/reels/useReelsSavedLessons.ts
 * - notSaved: src/screens/reels/usePlaybackPreferences.ts
 * - retryLater: `retry` plus the Egyptian "بعد شوية" for "بعد قليل".
 *
 * A lead clause joins the retry with "و" (`${lead} و${learnerCopy.retry}`),
 * the same join as the shipped networkRetry.
 */
export const learnerCopy = Object.freeze({
  retry: 'جرب تاني',
  networkRetry: 'اتأكد من النت وجرب تاني',
  notSaved: 'متحفظش التغيير',
  retryLater: 'جرب تاني بعد شوية',
} as const);
