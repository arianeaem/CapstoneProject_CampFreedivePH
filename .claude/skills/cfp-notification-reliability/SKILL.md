---
name: cfp-notification-reliability
description: Use when working on Camp Freedive PH notifications, the checkout/payment-exception outbox, queue jobs, Laravel Scheduler commands, or email/mail wiring (B6). Protects transaction-safe delivery and local-only test transports.
---

# cfp-notification-reliability

## Purpose
Support reliable, idempotent notification delivery (checkout, payment exceptions, weather alerts, coach matching) without hitting real external services in dev/test.

## When to use
Changes to `app/Jobs/*`, `app/Mail/*`, `app/Services/{Checkout/CheckoutNotifier,AdminNotificationService,CoachNotificationService,WeatherRiskNotifier}.php`, notification-related console commands, or `routes/console.php` scheduler entries.

## When not to use
Pure UI copy changes with no delivery-path impact.

## Relevant source references
- `app/Jobs/SendCheckoutNotification.php`; `app/Services/Checkout/CheckoutNotifier.php`; models `CheckoutNotification.php`, `NotificationLog.php`
- `app/Services/{AdminNotificationService,CoachNotificationService}.php`; `app/Mail/*`
- Console: `SendCheckoutNotificationsCommand.php`, `NotifyCoachMatchingCommand.php`; scheduler entries in `routes/console.php`
- Tests: `tests/Feature/Checkout/CheckoutNotificationTest.php`, `tests/Feature/AdminOperationalNotificationTest.php`

## Business invariants
- Notifications dispatch after the DB transaction commits, not inside it — a rollback must not leave a sent notification referencing data that never persisted.
- Delivery is idempotent — retries/duplicate dispatch must not double-notify a guest or staff member.
- Failed deliveries must be visible (logged/queryable), not silently dropped.
- Guest-facing wording and staff-facing wording are different audiences — don't reuse staff-internal phrasing in guest notifications.
- `MAIL_MAILER` must never be `log` in contexts where the project's past batches required real queued delivery — check current `.env`/config intent before assuming `log` is safe to leave as-is.

## Safe workflow
1. In dev/test, never configure a real mail/SMS transport — use the array/log/fake transport Laravel test helpers already provide (`Mail::fake()`, queue `Bus::fake()`), and confirm no outbound network call is made.
2. For outbox-pattern changes, verify dispatch happens via `afterCommit` (job) or an equivalent commit-safe hook, not inline mid-transaction.
3. Run `tests/Feature/Checkout/CheckoutNotificationTest.php` and `AdminOperationalNotificationTest.php` after any change in this area.
4. Do not activate production queues, cron entries, or external notification services as part of local work — assume queue workers/scheduler aren't running in production until confirmed.

## Stop conditions
- A change would require sending a real email/SMS during a test run.
- Dispatch would need to happen before the owning transaction commits.
- Scope would require enabling a production queue worker or cron job.

## Expected output
Confirmation of which test transport was used (fake/log, never real) and which notification tests were run.
