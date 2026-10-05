# Profile overview independent reads

Local source change in `useProfileOverview.ts` removes a loading dependency between account identity and reviewed portfolio status. Fresh name, avatar and certificate-holder name no longer wait for the portfolio response. Runtime verification remains deferred to the final combined gate.

## Reference and reuse

The reference is [Bluesky Profile.tsx at revision 61c7cad](https://github.com/bluesky-social/social-app/blob/61c7cad053ccf25e9bee70be8b3148a4a1c25e1f/src/view/screens/Profile.tsx). Its account profile loads the header independently of additional labeler information and tab data. [Bluesky's 2025 transparency overview](https://bsky.social/about/blog/01-29-2026-transparency-report-overview), published January 29 2026, reports growth from 25 million to 41 million users during 2025. This establishes real adoption, not a Rokn latency benchmark.

This is architectural adaptation, not copied Bluesky source. No compatible reference adapter for Rokn's reviewed portfolio and account-session ownership was identified. The implementation reuses the installed React Navigation focus lifecycle, existing API methods and account-session boundary checks. It does not add Bluesky's query stack, a dependency or a second loading system.

## Contract

- Verify the account session once, then start the account and portfolio reads independently. Each current response publishes immediately without waiting for the other endpoint.
- Keep account and portfolio read errors separate. A successful response or share recheck clears only its own error. The existing single UI error field presents the outstanding error without changing the approved page design.
- Ignore responses after focus loss, a newer retry or an account-session change. Gallery changes and newer share checks also invalidate older portfolio reads.
- Keep a more recent saved account revision ahead of an older response. An intentionally cleared headline remains empty rather than restoring an older portfolio value.
- Sharing still requires a reviewed approved status, no suspension, actual gallery work and a trusted public URL. Neither account identity nor a slug authorizes sharing. Share and QR actions re-read portfolio status before use.
- Guest sessions request neither private endpoint.

## Backend and dashboard consistency

`StudentProfileResource` continues to supply canonical name, `profile_image`, `profile_revision` and `portfolio_headline`. `PortfolioProfileController` independently reconciles moderation status and supplies `public_url` only for approved work. The admin user profile uses the same canonical user name and `profile_image_url`. No API schema, backend controller or dashboard view change is needed for this loading dependency; existing unrelated changes in these files are preserved.

## Acceptance and remaining verification

Independent source review accepted the separation of reads, independent errors and stale-response guards. It found no daily-use blocker within this unit. This is source acceptance, not proof of executed behavior or overall project completion.

Authored but not run: `profileOverviewReadSettlement.test.tsx` covers fast identity with slow portfolio, the inverse order, immediate independent failures, recovery, sharing recheck error isolation, retry retirement, gallery invalidation, account changes, blur/refocus and guests. Existing `profileIdentityFreshness.test.tsx` and `portfolioShareIsolation.test.tsx` remain unchanged and part of the final regression gate.

The final gate must run these suites with lint and type checking, then verify on a phone that delaying either endpoint does not delay the other's result or permit stale sharing. No tests, build, commit, push or deployment performed for this unit. No mobile version bump.
