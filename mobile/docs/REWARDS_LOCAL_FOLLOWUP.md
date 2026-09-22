# Rewards screen — local follow-up 2026-09-19

Scope: the approved rewards preview is implemented in the current monorepo at
`E:/Rokn/rokn-course-platform-review`. This is local work only. No commit, push,
server deployment, production migration, Play upload, or device installation
is part of this change. The submitted reviewer build remains untouched.

## Application

- The existing Wallet route/deep link now presents **مكافآتي** with the original
  coin artwork, earned balance, **كيف يعمل الرصيد**, and **اكسب عملات**.
- `WalletView`, `RewardsTaskList`, and `RewardsDetailsSheet` separate screen,
  task actions, and on-demand details. Account ownership, cache, settlement,
  retries, and server mutations remain in their existing owners.
- The rewards controller does not mount the checkout hook and explicitly opts
  out of package reads. It cannot start a top-up. The existing package API and
  billing implementation remain available for course checkout and compatibility.
- Claimed tasks are collapsed. Welcome/registration is never a manual task.
  Social marks and action-first task labels remain visible. Authored campaign
  titles are preserved; only known generic legacy titles are localized.
- Earned coins are not mixed with paid coins in the main balance. Paid credit
  remains in the ledger and course checkout; its breakdown is available in the
  help sheet when nonzero. No balance conversion or financial write was added.
- The optional balance breakdown shows only paid/reward/total balances. It
  does not present a generic per-course allowance; the selected subscription
  owns the actual discount and amount due.

## Backend and dashboard

- `wallet.rewards` is an additive projection containing reward balance, help,
  and the latest ten reward transactions. Existing wallet response fields are
  unchanged. Mixed purchase debits contribute only their reward portion.
- New mobile code can also read the existing server ledger's `reward_coins`
  fields without requiring deployment while the reviewer build is frozen.
- Completed campaigns remain in the owner's completed list after expiration
  or exhaustion. Start/claim eligibility checks still reject retired campaigns.
- The dashboard separates automatic rewards, **اكسب عملات**, and the new help
  text. Existing wallet/web copy is retained in a disclosure, not overwritten.
- New `rewards_help_ar` / `rewards_help_en` fields are optional and limited to
  600 characters. Saves retain optimistic concurrency and invalidate the
  existing wallet cache. Empty help uses a short localized default.

## Future release prerequisite

Apply `2026_09_19_000001_add_rewards_help_to_settings.php` in the target release
environment before editing the new help fields. It adds two nullable columns
and does not mutate balances, packages, task receipts, or existing help copy.
Do not run it on the reviewer server without renewed deployment authorization.

## Verification

- Application TypeScript and scoped ESLint pass.
- Full Jest run: 281 suites and 2187 tests pass.
- Focused backend/dashboard tests: 49 tests and 639 assertions pass.
- Full backend run: 1816 tests, 17960 assertions, no failures or errors, and
  5 skipped tests. PHPUnit used the repository's in-memory SQLite test config
  and the installed PHP extensions enabled for that invocation only.
- Tests cover reward-only history, mixed debits, old-server compatibility,
  malformed projections, automatic welcome exclusion, completed campaigns,
  independent package loading, local states, and 320–800dp/font-scale variants.
- Native device/emulator rendering and a real store purchase have not been
  exercised for these new local changes. No replacement binary was built.
