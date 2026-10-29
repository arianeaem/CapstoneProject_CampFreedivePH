---
name: cfp-release-readiness
description: Use when asked to prepare a release-readiness review, go/no-go assessment, or evidence summary for Camp Freedive PH. Distinguishes implemented-locally vs tested-locally vs approved-for-release vs deployed vs operationally-verified; never upgrades a status without checked evidence.
---

# cfp-release-readiness

## Purpose
Produce evidence-based readiness reviews that don't overstate how far a feature actually is.

## When to use
Explicit request for a readiness review, go/no-go assessment, or audit summary spanning multiple modules.

## When not to use
A single bug fix or isolated feature change — use the domain-specific skill instead.

## Required initial checks
Before writing any readiness claim, check for each area in scope:
- Is it implemented in the current branch (file exists, code path reachable)?
- Is it covered by a passing local test (which file, run when)?
- Has a human (Owner/Admin/client) approved it for release — distinct from tests passing?
- Is it actually deployed (which branch/environment)?
- Has it been operationally verified post-deploy (monitoring, real usage), not just deployed?

Never collapse these five into one "done" — report each explicitly, per area.

## Scope areas to check when relevant
Security/access control (`cfp-booking-lifecycle`'s PIN protections, `AccessControlAuditTest.php`), financial integrity (`cfp-payment-integrity`), booking capacity/operating dates (`cfp-booking-lifecycle`), coach scheduling (`cfp-coach-scheduling` — note the capacity gate is intentionally fail-closed, this is a known blocker not a defect to "fix" silently), weather safety, demand forecasting/pricing, notifications (`cfp-notification-reliability`), monitoring/backups/restore, logging/secrets handling, deployment docs, rollback plan, UAT status.

## Safe workflow
1. Walk the scope list; for each area, cite the actual file(s)/test(s) checked, not a general impression.
2. Explicitly list known, already-documented blockers (e.g. coach-capacity gate fail-closed; refund approval without payout execution) rather than re-discovering them as "surprises."
3. State unresolved risks and open questions as a distinct section, not buried in prose.
4. If evidence wasn't actually gathered for an area (couldn't run tests, couldn't check deploy status), say so explicitly rather than inferring "probably fine."

## Stop conditions
- Asked to mark something "production-ready" based only on local tests passing — report test status, not readiness, and flag the gap.
- Asked to check actual production/deployment state — that requires explicit authorization per `cfp-safe-development`; report what can be checked locally and name what's missing.

## Expected output
A report structured by the five statuses above per scope area, an explicit blockers list, and an explicit unresolved-risks list — never a single "ready"/"not ready" verdict without that breakdown.
