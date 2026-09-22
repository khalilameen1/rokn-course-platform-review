# Local checkout follow-up — 2026-09-19

Current implementation base: `c9c843a29ca3706d661d1156c791f75ab19bdbea`
in `E:/Rokn/rokn-course-platform-review`. This implementation remains local;
it has not been committed, deployed or uploaded to Google Play.

## Approved subscription design implemented

- One shared sheet for purchase and upgrade, with three compact plan choices
  and the selected plan's actual details underneath. As corrected on 2026-09-22,
  choices remain horizontal even with enlarged system text. At extreme text
  sizes the row scrolls horizontally; details remain vertically scrollable.
- Basic shows watching included and chat, projects/reports and certificate
  excluded. Plus and Pro show effective limits from the API. Theory courses
  do not advertise projects.
- One `كود منحة` entry, only for Basic on first purchase. No coupon field and
  no grant entry on Plus, Pro, upgrades or pending store transactions.
- Payment copy is `حصلت على خصم` and `المطلوب دفعه`. Native store pricing,
  payment locking and recovery remain in the existing checkout controller.
- Successful grant activation stays in the sheet with `تم تفعيل المنحة`,
  watch-only rights and `ابدأ الكورس`. Free watching appears once. Prices,
  payment summary, plan choices and code entry disappear. Replaying an already
  activated grant shows the same rights without a second analytics claim.
- The preview-only code `1234567` has NOT been added to application or server
  redemption logic. Real codes are created and validated through the dashboard.

## Backend and dashboard parity

- Fixed the legacy no-plan fallback that allowed code enrollments to submit
  projects. Code access is watch-only until an actual paid plan is captured.
  The same rule now drives learning progress, project gates and staff summaries.
- Code enrollments without a paid plan cannot earn certificates, including
  ordinary course codes without the institutional lifetime-grant flag.
- Redemption and replay return explicit learning, chat, project and certificate
  rights. Notifications and certificate gating no longer promise grant projects.
- Dashboard plan descriptions use the same effective capability projection as
  the app API, including course-specific project presence. Dashboard grant
  preview explicitly excludes projects, chat and certificates.
- Dashboard grant wording is not university-specific. The existing `is_grant`
  checkbox is labelled by its actual effect, one grant per account. Optional
  email-domain restrictions and code usage limits are retained.
- No new checkout endpoint, parallel pricing logic, test-code bypass, schema
  migration or production data rewrite was introduced. Existing paid plan
  snapshots and upgrade difference/no-reward rules remain authoritative.

## Verification of the final local implementation

- TypeScript and ESLint on the changed mobile files passed.
- Full final mobile suite: 280 suites, 2182 tests passed.
- Full backend run: 1811 tests, zero assertion failures, five MySQL-specific
  tests skipped in the local SQLite environment. One newly added preview test
  initially had incomplete fixture data (`tenant_id`); its fixture was corrected
  and the complete watch-only test class then passed (5 tests, 48 assertions).
- Final focused backend run covering code redemption, entitlement consistency,
  dashboard preview, student progress, checkout and reward/upgrade rules passed:
  51 tests, 441 assertions. The full backend suite was not rerun after the
  fixture-only correction.
- `git diff --check` passed. No native Android build or device checkout was
  performed in this turn. Device layout, native store purchase/recovery and the
  real MySQL constraints still need release-environment verification before
  uploading a replacement build.

## Historical follow-up — 2026-09-15

The following describes the earlier local iteration and is superseded by the
approved copy and behavior above.

Base: `b3b6209`, the production monorepo at `E:/Rokn/rokn-course-platform-review`.
These changes are local only. No commit, deployment, store upload or replacement
of the submitted version 60 artifact was performed in this follow-up.

## Changes

- Basic, Plus and Pro retain one shared subscription sheet and payment controller.
- Payment summary labels are `خصم المكافآت` and `المطلوب دفعه`.
- `كود الجامعة` opens exactly one input with the action `تفعيل`.
- Removed unused generic-coupon UI and legacy purchase/coupon orchestration from
  the course-details controller and dialog contract. Old coupon return parameters
  are cleared, not re-quoted, when resuming this flow.
- University redemption uses its own component without a second repeated heading.
- Prices, reward eligibility, upgrade charges and backend financial rules are unchanged.

## Verification

- TypeScript and ESLint on changed files passed.
- All 276 Jest suites passed, 2140 tests. Retired coupon-orchestration tests were
  replaced with tests of the current shared-checkout ownership and return flow.
- Tier-by-tier rendering verifies one university field and identical payment labels.
- No updated native build was installed or tested on the owner's phone.

## Reported version discrepancy

The owner reports these problems on installed version 60. The connected emulator
reports 59; this is not evidence about the owner's phone. A read-only scan of
the local version 60 AAB's embedded JavaScript found the previous label
`تم خصم من عملات المكافأة`, but not `من رصيدك المشترى`, `مكافآت مستخدمة`,
`معك كود` or `معاك كود`. The owner-device discrepancy remains unverified.
Future device verification should identify the actual installed artifact, not
infer its version from the emulator.
