---
name: cfp-coach-scheduling
description: Use when designing or implementing authoritative coach/instructor scheduling (S1-S5) for Camp Freedive PH, or touching InstructorCapacityVerifier, CoachAssignment, or coach availability. The capacity gate is currently fail-closed by design - do not enable it without explicit client approval.
---

# cfp-coach-scheduling

## Purpose
Guide future authoritative coach-scheduling work without prematurely enabling or relaxing the current fail-closed capacity gate.

## When to use
Any task referencing S1-S5 coach scheduling, `InstructorCapacityVerifier`, coach assignment/availability, or instructor-to-student ratio.

## When not to use
Coach portal UI-only changes that don't touch capacity verification or assignment state.

## Current state (verified, do not assume otherwise)
- `app/Services/Payment/Allocation/InstructorCapacityVerifier.php` is a contract/interface.
- `app/Services/Payment/Allocation/UnconfirmedInstructorCapacity.php` is the **only** implementation currently bound (in `app/Providers/AppServiceProvider.php`). Its own docblock states it never confirms instructor capacity, so every allocation is blocked for staff scheduling review — an intentional fail-closed (P3) stub, not a bug.
- Underlying data can't yet support real verification: coach-availability records use free-text batch linkage and start-date-only availability, with no shared locking — not a basis for an authoritative ratio check yet.
- Coach domain files: `app/Models/{Coach,CoachAssignment,CoachAvailability,CoachBlackoutDate,CoachOpening,CoachRequest}.php`; `app/Http/Controllers/Coach/{AvailabilityController,PortalController,RequestController,ScheduleController}.php`; `app/Services/{CoachMatchingService,CoachWorkloadService,CoachNotificationService}.php`.
- Tests: `tests/Feature/{AdminCoachMatchingModuleTest,CoachPortalModuleTest,CoachListExportPrintTest}.php`.

## Business invariants
- Hard maximum ratio: 1 coach to 4 students — never design around a higher ratio.
- Availability (coach says they're free) and assignment (staff actually assigns) are separate states — don't collapse them.
- Camps run as two-day sessions; a coach can't be double-booked across conflicting camp-days.
- Only active staff/coaches should be assignable.
- Do not require coach certifications as a gating rule unless the client explicitly approves that requirement.
- Do not auto-assign all available coaches to a batch — assignment is a deliberate Owner/Admin action.
- Do not assume a fixed two-coach minimum per batch is approved policy.

## Safe workflow
1. Before any S1-S5 work, confirm with the client/user whether this specific task is building toward making the gate authoritative, or working within the current fail-closed state. Don't assume either.
2. Never flip `AppServiceProvider`'s binding away from `UnconfirmedInstructorCapacity` without an explicit, separately-approved rollout decision — this is a safety gate, not dead code.
3. New scheduling logic should go through `CoachMatchingService`/`CoachWorkloadService` rather than bypassing them with ad hoc capacity math.
4. Any concurrency-sensitive assignment logic (two staff assigning the same coach-day simultaneously) should be verified using `cfp-postgresql-race-testing`'s independent-process approach, since no shared locking exists yet in availability data.

## Stop conditions
- A task asks to enable/relax the capacity-verification gate without a stated client approval.
- A task assumes coach certification requirements, a fixed coach minimum, or auto-assignment that isn't already documented/approved.
- Underlying availability data genuinely can't support the ratio check needed — report the gap rather than faking verification.

## Expected output
Explicit statement of whether the gate remains fail-closed after the change, and which invariants above were preserved.
