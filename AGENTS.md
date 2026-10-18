# Rokn working instructions

Read and follow `.cursor/rules/proven-reference-first.mdc` for every task in this repository.

The user's standing preference is to reuse successful, established implementations and shipped UX, adapting only what Rokn requires. Identify the actual source and evidence of adoption before implementation. Do not present an independently written implementation as reused reference code.

Preserve approved designs, existing user changes and compatibility across mobile, backend and dashboard. Never claim payment integration or release readiness from unit tests alone.

## Engineering rules

`ARCHITECTURE.md` is the single doctrine: one accepted way per concern across backend, mobile and dashboard, with the tool that enforces it and the convergence counts that may only go down. Follow it; a second way to do the same thing is a defect even when it works.

These came out of the October 2026 launch review. Each one closes a failure that actually happened in this codebase.

- **Controllers delegate.** An HTTP controller validates, calls one application service, and shapes the response. Transactions, locks, wallet and enrollment writes live in services.
- **One writer per aggregate.** Enrollments are written only through `CourseEnrollmentWriter`, wallet balances only through `WalletService`, order approval only through `OrderLifecycleService`. Add a method to the owner instead of writing the table elsewhere.
- **Lock order is user, then course, then other rows.** Readers that only need a stable course take `sharedLock()`; only publishing takes the course exclusively. Never upgrade a shared lock to exclusive inside one transaction.
- **GET never writes.** Remapping, backfills and repairs belong on write paths, queued jobs or migrations.
- **Merged migrations are append-only.** `database/migration-lock.json` holds their checksums and `MigrationLockTest` fails on any edit. Add a new migration instead; run `php scripts/lock-migrations.php --write` after adding one.
- **Test behaviour, not source text.** New tests go through HTTP, services or rendered views. `SourceTextTestRatchetTest` blocks new source-reading tests; convert listed ones when you touch them.
- **Branch on codes, not messages.** Refusals carry stable codes (`CourseCheckoutRejected`, `KashierCheckoutConflict`); never compare exception text.
- **Delete what you replace.** When a new path supersedes an old one, remove the old code, its routes and its tests in the same change.
- **Small commits.** One concern per commit, named reference in the message. A commit touching hundreds of files cannot be reviewed.
- **Learner-facing AI text** goes through `AiPromptPolicy` and `CoachReplyText`; do not add another prompt or formatter elsewhere.
