# Camp Freedive PH

Weather- and demand-adaptive booking platform with coach scheduling, for freediving camps. Laravel app lives in `camp-freedive-ph/`.

## Stack
Laravel 13, PHP 8.4, PostgreSQL 17 (dev/prod), SQLite (some tests), Tailwind, Alpine.js, Vite. Separate Python components at repo-root `demand-forecast/` and `safety-forecast/` (not Laravel). Open-Meteo Marine API for weather. PayMongo for payments. Azure App Service + nginx (`camp-freedive-ph/deploy/nginx/default.conf`). CI: `.github/workflows/{laravel.yml,demand-forecast.yml,feature-prd-forecast_camp-freedive-ph.yml}`.

## Layout
- `app/Services/{Checkout,Payment,Refunds,Booking,Weather,Analytics,Gateways,ExternalApi}` — domain logic
- `app/Http/Controllers/{Admin,Coach,Auth,Payment}` — role-scoped controllers
- `database/migrations/` (flat, 46+ files) — no separate seeders dir for prod data
- `tests/Feature/{Checkout,Payments,Refunds,Capacity}`, `tests/Unit`
- No `tests/Race` dir — Postgres/concurrency coverage lives inline in `*Pgsql*Test.php` / `*Postgres*Test.php` files

## Safety rules (local-only, always apply)
- Never push, merge, open a PR, deploy, or touch production/Azure/CI config unless explicitly asked.
- Never touch the protected branch `feature/prd-forecast` directly.
- Never run migrations against, or otherwise mutate, a persistent/production database. `.env` points at a protected local DB — never run plain `artisan` commands that write to it (`route:list`, `schedule:list`, etc. have done this before); scope env vars per-command instead.
- Never make real PayMongo calls in tests or dev scripts.
- Never leak `PAYMONGO_*` env vars from the parent shell into PHPUnit — `phpunit.xml` already blanks them via `<env>`, but that doesn't override process env already set before PHPUnit starts. Scope test env per-process, not via `export` in the parent shell.
- Don't commit unless explicitly asked; review `git status`/`git diff` before any commit.

## Financial/booking invariants (see skills for detail)
- Payment exceptions and refunds are policy-blocked by design in places — approval of a refund is not payout; don't treat "approved" as "paid".
- Coach/instructor-capacity confirmation is fail-closed (`UnconfirmedInstructorCapacity` is the only bound `InstructorCapacityVerifier`) — don't treat it as enabled without explicit client sign-off.
- Cancellation/reschedule requests retain capacity until approved; conflicting guest requests aren't allowed.

## Skills
Project skills live in `.claude/skills/`. Each covers one domain (safe dev workflow, Postgres race testing, payment integrity, booking lifecycle, coach scheduling, notifications, release readiness) with its own checks and stop conditions — read the relevant skill before touching that area instead of relying on this file alone.
