# Course checkout commercial policy

## Offer and receipt versions

- Stable API codes remain `basic`, `guided`, `mentor`; the new default names are Basic, Plus, Pro.
- New Basic definitions and explicit course-editor saves produce watch-only offers: `projects_enabled=false`, no chat, project reports, follow-up or certificate.
- The schema migration does **not** rewrite existing live Basic offers. Publish the new offer through normal staged course authoring/revision controls.
- Purchased order/enrollment snapshots are immutable. Version 6 explicitly includes `projects_enabled`. Versions 1–5 preserve their historical project entitlement, even after the live offer changes.
- `CourseAccessPlanService::projectsEnabledForEnrollment()` owns legacy fallback and fails closed for malformed plan-backed receipts.
- Deploy the version-6 MySQL CHECK migration before enabling new writers. It retains all historical schema branches. Do not restore an older CHECK after version-6 orders exist.

### Code ownership

`CourseAccessPlanService` reads available offers, captures receipts and projects
their public capabilities. It has no authoring or entitlement-grant methods.

`CoursePlanAuthoringService` owns editor defaults and atomic three-tier saves.
It also applies global runtime policy to future offers. Definitions and policy
mapping live in `CoursePlanDefinitionService`; financial floor calculations
remain in `CoursePlanEconomicsService`. Do not duplicate either rule in a
controller or form. Editor previews must not create database rows.

`CoursePlanAttachmentGrantService` is the explicit, additive exception for
enrollment attachment rights. Both the admin action and queued publication
signals use it. It selects the model's active, unexpired enrollment scope and
re-reads each enrollment under a row lock before updating its snapshot. It
does not rewrite the order receipt, change the purchased tier or reduce an
existing attachment allowance. Replaying an already-applied grant is a no-op.

`CoursePlanOwnershipTest` covers whole-edit rollback, purchased receipt
preservation, additive/idempotent grants and inactive/expired exclusions.
The editor and staged-publication tests cover the dashboard call sites and
stable plan identities. A local SQLite run does not prove MySQL lock scheduling;
keep the separate MySQL contract checks in the release verification path.

### Explicit Basic-only activation

After the new code and schema pass deployment checks, inspect each intended canonical course's current `authoring_version`. Run one course per invocation, substituting its actual ID and version:

```text
php artisan courses:activate-watch-only-basic <course-id> --expected-version=<current-version>
php artisan courses:activate-watch-only-basic <course-id> --expected-version=<same-version> --apply
```

The default is a read-only readiness preview. `--apply` creates its own fresh staged draft and publishes through the usual health/revision checks, atomically. Any pre-existing draft, stale version, missing plan identity or publication failure stops the command; there is no force/override. A failed publication rolls back the draft as well as its changes. An already watch-only Basic is a no-op when the expected version is current.

Only Basic's chat/project/certificate capabilities and their unused budgets are disabled. Plan names/IDs, all coin prices and paid floors, delivery-cost inputs, Plus/Pro terms, catalogue visibility and the main-course choice are preserved. Existing order/enrollment receipts are not rewritten. A successful publication advances the authoring revision normally and follows the normal course-update notification path; this is reported in the command output. It is not a silent direct SQL edit.

Do not use `syncAdminPlans()` for this narrow rollout: that editor save intentionally resynchronizes all three tiers against the global runtime policy. Do not reuse an existing draft, disable readiness or fabricate cost inputs to force activation.

## Promotions

`settings.max_course_promotion_percent` is the combined percentage ceiling for earned coins and coupons. Its initial value is 20. The older `max_reward_contribution_per_course` remains an independently stored historical **coin amount**, not a percentage.

Purchased coins are not promotional discounts. The checkout policy applies the combined allowance cumulatively across upgrades. The admin editor version includes the percentage so stale forms cannot overwrite a newer policy silently.

## Pricing controls

The suggested Basic/Plus/Pro price positioning is 0.60 / 1.00 / 1.50 relative to Plus. These are suggestions, not automatic changes to existing live prices.

Each plan's nullable `delivery_cost_usd` records the allocated per-enrollment cost of content production, delivery, support and mandatory project checks. It excludes the provider budgets already held by the server. NULL is uncosted; it is never interpreted as zero. Only an administrator can submit this field through the course editor.

`ROKN_NET_USD_PER_PAID_COIN` must be the conservatively verified net retained value after payment fees, indirect taxes and FX. It is **not** the retail face value, and no fee/tax deduction is applied again in the floor calculation. Use the least funded enabled channel/package exposure when adopting one global value. This planning model does not replace settled-lot accounting.

The model computes

```
full cost = delivery allocation + active provider budgets × safety multiplier
required purchased coins = ceil(full cost / (net value × (1 − target margin)))
required list price = ceil(required purchased coins / (1 − promotion ceiling))
```

The default contribution target is 40 percent before income tax. Both the offer price and its purchased-coin floor must pass. Costed offers are checked on course saves, publication, global provider-capacity changes and promotion-policy changes.

Set `ROKN_ENFORCE_COMMERCIAL_FLOOR=true` for commercial activation after finance inputs are complete. New purchases/upgrades must call `CourseAccessPlanService::assertPurchasableEconomics()`. In strict mode existing uncosted offers are blocked with a generic public availability message; provider cost data is not returned to the learner. This check never changes an old purchase receipt.

An explicit internal-test configuration can leave strict mode off. The dashboard and publication warnings then label uncosted offers unverified. Successful internal purchase testing is not evidence that the test prices are profitable commercial prices.
