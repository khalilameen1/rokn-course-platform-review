# Welcome prompt presentation policy — local source, 4 October 2026

## Reference and actual reuse

[OneSignal's in-app trigger/frequency guide](https://documentation.onesignal.com/docs/en/iam-triggers)
separates display timing, dismissal and repeat frequency. Its shipped default is
one display per subscription; recurrence requires a separate explicit policy.
[Historical adoption](https://onesignal.com/blog/record-breaking-momentum-and-exceptional-growth-in-2019/)
records over 10,000 IAM customers in 2019. Documentation alone is not proof of
adoption, and neither source measures Rokn or proves its runtime integration.

This is architectural/authoring inspiration, not copied SDK code or OneSignal
installation. No matching reusable source for Rokn's keyed welcome reward and
Laravel template editor was found. Actual reuse is the existing Laravel
FormRequest, locked template writer, shared editor version, availability reader,
welcome reward resolver and installed app's installation receipt. No additional
library, database schema, campaign service or alternate template editor exists.

## Approved fixed journey vs authored content

The approved welcome is shown once on an installation, before registration, can
always be dismissed, and its CTA enters the app's existing login journey. These
are product invariants, not administrator-configurable recurrence/deep links.
`AdminNotification::presentationOverrides` holds the fixed guest surface,
dismissibility, zero recurring cooldown, internal priority and absent content URL.
The request, locked writer, public message and editor use that same policy.

The editor no longer advertises recurring cooldown, priority, alternate surface,
content destination or nondismissibility for this single keyed welcome template.
It describes the fixed flow instead; the listing does not display old stored
cooldowns/priority as effective welcome behavior. Title, visible button labels,
image, active flag and start/end availability retain their existing authorship.
The amount continues to come from the existing welcome reward rule, not text.

An older editor can submit its legacy fields, including `/login`; FormRequest
normalizes fixed options and validates both button labels for an active welcome.
Its app-owned login CTA is not forced through generic content-link pairing.
Other templates keep their authored options and existing link validation.

The writer resolves presentation policy after locking and restoring the immutable
system identity, never from a tampered submitted replacement key. Reading an old
stored welcome projects effective policy without rewriting its copy, schedule,
image, version or reward ledger. There is no destructive normalization migration.
API keys/types are unchanged, including the old dismissible/cooldown/link fields.
The mobile's approved one-installation receipt and one-launch presentation slot
remain authoritative; zero cooldown does not enable repetition. No client-only
reward grant, copied login router or post-login welcome popup is introduced.

## Deferred acceptance

Authored, not executed: real FormRequest legacy/omitted-key saves, active label
validation, fixed policy read without database mutations, writer identity and
nonwelcome isolation, effective editor controls and dashboard-owned reward amount.
Existing template image/version/concurrency tests and mobile welcome/startup
suites remain final-gate requirements. Tests, lint/typecheck, build, push and
deployment were not run for this unit.

Final runtime acceptance must save/disable/schedule the template in the matching
dashboard, read the guest API and open a fresh-install signed app. Verify the
configured amount/image/copy, login and guest dismissal, no repeat on re-entry or
template edit and no extra popup after login. Static review does not prove that
dashboard-to-phone journey or complete any other active-goal inventory unit.

Independent source review accepted this unit without a remaining practical
finding in its welcome-policy scope. The reviewer inspected request normalization,
locked system identity, nonwelcome isolation, read-only projection, editor controls
and authored cases without running tests or changing files. This is source/contract
acceptance only; the signed dashboard-to-phone gate above remains open.
