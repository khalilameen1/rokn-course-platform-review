# Third-party AI consent release

`third-party-ai-v1` is one affirmative, account-bound choice covering course chat,
project submissions (including participation assessment), initial project reports,
follow-up questions, and their attachments. The first learner-initiated action
explains OpenRouter/model providers, transmitted content and context, and offers
Agree, Not now, and Privacy policy. Declining keeps local drafts. Settings →
Privacy → AI data sharing allows withdrawal. Previously saved results remain readable.

Deploy the `2026_09_12_230000_add_ai_consent_to_users` migration before the new
backend code. Publish the mobile release with this gate before enabling it for
all learners; coordinate the deployment window with the store release. Old apps
receive `ai_consent_required` for new AI/project-assessment work and must update;
learning, account operations and read-only history do not require consent. There
is no grandfathering, version fallback, or silent acceptance from legal terms.

Queued work checks current consent at the serialized provider-start boundary.
Missing/revoked consent stops the request, releases its reservation and preserves
an explicit retry path. Already-started provider calls can complete; revocation
does not pretend that already-transmitted data was never sent. Stored results can
be recovered without another provider call. Background outboxes never prompt and
retain drafts until an explicit consent action succeeds.

Assistant messages and project reports can be reported from their response. The
server checks ownership, copies only the reported output into the staffed support
queue and deduplicates retries. Reports must be actively reviewed and used to
improve restricted-content safeguards; merely accepting reports is not moderation.

Release QA: fresh-account decline/agree, privacy-link return, reinstall, account
switch while dialog is open, offline consent, withdrawal while work is queued,
consent→retry for all four AI flows, valid/foreign-output reports, and support
receipt visibility. Use real devices for the native alert over chat/project modals.
