---
name: cfp-postgresql-race-testing
description: Use when writing, running, or debugging PostgreSQL concurrency/race tests for Camp Freedive PH (capacity holds, checkout finalization, refund ledger). Covers env isolation, lock review, and the historical PAYMONGO_* leak bug.
---

# cfp-postgresql-race-testing

## Purpose
Repeatable, safe concurrency verification against a disposable PostgreSQL 17 cluster — never a persistent/shared database.

## When to use
Touching capacity/checkout/refund-ledger concurrency logic, or running/debugging the `*Pgsql*Test.php` / `*Postgres*Test.php` suites.

## When not to use
SQLite-only unit tests with no concurrency concern.

## Required initial checks
- Confirm `DB_CONNECTION`/`DB_DATABASE` in the test run point at a disposable Postgres instance, not the protected local DB referenced in `.env` (see `cfp-safe-development`).
- Confirm no `PAYMONGO_*` variable is already set in the parent shell process before invoking PHPUnit: `echo $PAYMONGO_SECRET_KEY` (bash) / `$env:PAYMONGO_SECRET_KEY` (PowerShell) should be empty. `camp-freedive-ph/phpunit.xml` blanks these via `<env>`, but that only overrides variables PHPUnit itself sets — it does **not** unset a variable already exported in the parent process before PHPUnit starts.

## Historical issue (B5)
B5's first full Postgres run produced 26 failures because the race harness exported synthetic `PAYMONGO_*` values in the parent PowerShell process before launching PHPUnit; a clean run (no pre-set env) passed. Root cause: `phpunit.xml`'s `<env>` block sets defaults for the PHPUnit *process* but can't retroactively clear variables the shell already exported into that process's environment.

**Minimal fix to propose (do not apply without separate authorization — the harness itself is out of scope for this skill):** run race-test invocations in a subshell/sub-process that doesn't inherit the parent's `PAYMONGO_*` exports, e.g. spawn PHPUnit via `env -u PAYMONGO_SECRET_KEY -u PAYMONGO_PUBLIC_KEY -u PAYMONGO_WEBHOOK_SIGNATURE_SECRET ...` (bash) or a fresh PowerShell job without those variables, rather than relying on `phpunit.xml` alone.

## Safe workflow
1. Verify test DB identity before every run (step above) — not just once per session.
2. For races, launch both orderings as independent OS processes (not threads in one PHP process) — a race-before-phpunit leak in a single process leaks into every test in that process, not just the racing ones.
3. Review lock order (advisory locks vs row locks) in the service under test before assuming a timeout is a bug — distinguish expected lock contention from a deadlock.
4. Run the full regression suite after a race-specific fix, not just the race test, to catch incidental breakage.
5. Tear down the disposable cluster/database after the run; confirm no leftover connections.

## Stop conditions
- Can't positively confirm the target DB is disposable (not the protected local DB).
- A `PAYMONGO_*` variable is already set in the parent shell and can't be cleared before the run.
- A failure can't be classified as expected contention vs. deadlock vs. genuine race bug — report ambiguity rather than guessing.

## Expected output
Confirmed test-DB identity, env-isolation confirmation, pass/fail results for both race orderings, and (if a harness leak is suspected) a proposed fix described above rather than an applied harness change.
