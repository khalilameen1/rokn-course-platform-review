# Approved rewards-only screen

The human approved the final in-conversation preview on 2026-10-04, including
the correction: number beside the large coin stack, no visible "رصيدك" caption
and no separate small coin beside that hero number.

## Proven reference and adaptation

QANDA's published coin-screen image was visually inspected before the preview:
https://qandahelp.qanda.ai/hc/article_attachments/52814572080793
Its official Google Play listing verifies 50M+ downloads:
https://play.google.com/store/apps/details?id=com.mathpresso.qanda

This is adaptation of visible shipped UI, not reuse of unavailable QANDA source.
Reuse in Rokn is concrete: existing controller/read/task ownership, AppArtwork
coin_stack/coin slots, native Pressable/Modal and existing reward projection.
No extra dependency or purchase flow was introduced.

## Approved screen

- Header: مكافآتي and a visible السجل label beside the history icon.
- Hero: earned balance only, number beside coin_stack on normal phone widths.
  Enlarged text reflows instead of shrinking or truncating the amount.
- Task title/brand/reward and a filled primary action. Completed tasks stay
  collapsed until requested. Welcome rewards remain automatic, not claim tasks.
- Below tasks: كيف يعمل الرصيد as a full-width disclosure, matching the
  completed-tasks row, with a clear trailing chevron.
- Only two detail destinations: help and recent reward movements. The former
  paid-balance breakdown destination, component and obsolete tests are removed.
- Recent reward movements include a coin image and signed numerals explicitly
  isolated LTR within the RTL layout. This tail is labelled آخر حركات الرصيد
  because the current summary returns the latest ten, not a complete history.
- No top-up, store catalogue, app subscription or purchase action in rewards.

## Backend and dashboard parity

WalletQueryService remains the authority for reward balance and recent reward
credits/debits, excluding paid amounts even in mixed course debits. No ledger,
balance, settlement or purchase contract changes are required for this layout.

The existing coin-earning-methods dashboard controls task rewards, availability
and rewards_help. The existing design-settings upload controls coin_stack and
coin. AppArtwork resolves dashboard images with shipped offline fallback. Its
dashboard hint now identifies both welcome and rewards-balance placement.
Do not add hardcoded preview balances, task counts or mock history to runtime.

## Verification status

walletPresentation.test.tsx and walletDetails.test.tsx are updated with authored
cases for the approved screen, task actions, two detail destinations, signed
history and server help. Existing ownership/settlement tests are preserved.
Runtime tests, native visual inspection, build and deployment are not executed
in this unit; they remain part of the user's final combined verification pass.
Preview approval and source review do not prove native release readiness.

## Final gate — rendered task coin selection, 5 October 2026

The fresh presentation suite passed nine cases and failed one because exported
memo-wrapper identity could not find `RoknCoin`. The test now inspects the real
`AppArtwork` rendered inside that coin: exactly one `coin` asset at 18 by 18
with `contain`. No coin/artwork mock, production change or design change was
introduced. Signed reward numbers, task title/brand and original action,
touch targets, stale-data lock, completed-task disclosure, earned-only balance,
five viewport/font settings and absence of top-up remain asserted.

The complete suite passes 10/10. Independent read-only review accepted the
rendered-asset binding and preserved coverage without rerunning it. Evidence
in `mobile/.cache/final-gate-20261005/`:
`mobile-approved-wallet-presentation-reproduction.log` and
`mobile-approved-wallet-presentation-final.log`. This closes the renderer
selector failure, not native pixels, actual reward claims or release readiness.
