---
name: cfp-safe-development
description: Use before any Camp Freedive PH dev/verification task to confirm baseline branch/SHA, clean working tree, and commit/push/deploy boundaries. Use whenever starting work, before committing, or when scope/safety of an action is unclear.
---

# cfp-safe-development

## Purpose
Keep local development on this repo safe: verified baseline, no unauthorized commit/push/merge/deploy, no production or persistent-DB access.

## When to use
Start of any task touching this repo; before staging/committing; before running any command that writes to a database or touches CI/deploy config.

## When not to use
Pure read-only questions about code that touch no files or shell state.

## Required initial checks
1. `git rev-parse --show-toplevel`, `git branch --show-current`, `git rev-parse HEAD` — compare against the baseline the *current task* specifies, not a hardcoded SHA (HEAD moves after every commit).
2. `git status --porcelain` — must be clean (or only the files you intend to touch) before you start.
3. Confirm the protected branch (currently `feature/prd-forecast`) SHA without checking it out: `git rev-parse feature/prd-forecast` (local) or `git ls-remote origin feature/prd-forecast` (remote) — treat it as a checkpoint to verify, not a value that can never change.
4. If baseline mismatches the task's stated expectation, or unrelated uncommitted changes exist, STOP and report before touching anything.

## Safe workflow
- Scope edits to files the task actually names. Flag anything else found as a separate recommendation, don't fix it inline.
- Never `export` secrets or synthetic test credentials (e.g. `PAYMONGO_*`) into the parent shell before running PHPUnit — see `cfp-postgresql-race-testing` for why.
- Never run bare `artisan` commands (`route:list`, `migrate`, `db:seed`, `schedule:list`, etc.) — `.env` in this repo points at a protected local DB/cache; these have accidentally mutated it before. Scope env vars per invocation if a command is genuinely needed, and confirm with the user first.
- Commit only when explicitly asked. When asked: review `git diff`/`git status` for unrelated files or secrets before staging.
- Never push, merge, open a PR, or deploy without explicit instruction in that turn.

## Stop conditions
- Baseline branch/SHA doesn't match what the task expects.
- Unrelated uncommitted changes present.
- A requested action requires touching the protected branch, production, or a persistent DB.
- A command would require disabling a hook or using `--no-verify`/`--force`.

## Expected output
A short confirmation of baseline (root, branch, HEAD, tree status, protected-branch SHA) before substantive work begins, and an explicit statement of what was/wasn't committed at the end.
