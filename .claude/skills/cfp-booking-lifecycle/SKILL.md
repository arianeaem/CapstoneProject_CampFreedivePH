---
name: cfp-booking-lifecycle
description: Use when working on guest booking creation, booking-number/PIN lookup, checkout holds, non-operating dates, or cancellation/reschedule requests in Camp Freedive PH. Protects capacity-hold semantics and guest-request coordination invariants.
---

# cfp-booking-lifecycle

## Purpose
Protect guest booking creation, capacity holds, operating-date rules, and cancellation/reschedule request coordination.

## When to use
Changes touching `app/Services/Booking/*`, `app/Models/{Booking,CancellationRequest,RescheduleRequest,CheckoutIntent}`, `app/Http/Controllers/{BookingController,ManageBookingController,Admin/BookingRequestController}`.

## When not to use
Payment-verification logic itself (use `cfp-payment-integrity`) or coach-capacity gating (use `cfp-coach-scheduling`).

## Relevant source references
- Guest booking: `app/Http/Controllers/BookingController.php`, `app/Services/Booking/BookingRequestService.php`, `app/Services/BookingPolicyEngine.php`
- Booking-number/PIN: `app/Http/Controllers/ManageBookingController.php`, `app/Models/Booking.php`
- Checkout holds: `app/Models/CheckoutIntent.php`, `app/Services/Checkout/{CheckoutService,IntentBookingBuilder,BookingFinalizer,CheckoutPaymentProcessor}.php`, `app/Console/Commands/ReconcileCheckoutsCommand.php`
- Non-operating dates: `app/Services/Booking/{OperatingDate,DateNotOperatingException}.php`
- Capacity/requests: `app/Services/Booking/{CapacityCoordinator,CapacityException}.php`, `app/Http/Controllers/Admin/BookingRequestController.php`
- Tests: `tests/Feature/{BookingFlowTest,BookingValidationTest,MinorBookingConsentTest}.php`, `tests/Feature/Checkout/*`, `tests/Feature/Capacity/{GuestRequestCoordinationTest,StaleRequestDecisionTest,BatchRescheduleSafetyTest,BookingLifecycleIntegrityTest,NonOperatingDateTest}.php`

## Business invariants (preserve, do not relax)
- Cancellation requests retain their original capacity until the request is approved.
- Rescheduling requests retain original-date capacity until the actual move happens, not at request time.
- A guest cannot have two conflicting actionable requests open at once (e.g. a pending cancellation and a pending reschedule on the same booking).
- A full batch (camp session) means operating; non-operating dates must reject reservation attempts, not silently reassign them.
- Checkout holds are temporary and must expire/release capacity correctly — stale holds must not permanently lock capacity.

## Safe workflow
1. Identify whether the change is at request-time (capacity should NOT move yet) or approval/execution-time (capacity moves now) — most bugs in this area come from moving capacity at the wrong step.
2. Check `CapacityCoordinator` and the relevant request model's state machine before adding a new transition.
3. Run `tests/Feature/Capacity/*` and the directly affected `tests/Feature/Checkout/*` tests after any change.
4. For stale/conflicting request handling, check `StaleRequestDecisionTest.php` for the existing policy before adding new conflict logic.

## Stop conditions
- Change would let capacity move before an action is actually approved/executed.
- Change would allow two conflicting actionable requests on one booking.
- Non-operating-date validation would need to be bypassed to make a test pass — that's a sign the fix is in the wrong place.

## Expected output
Statement of which invariant(s) above the change preserves, plus the Capacity/Checkout test files run to confirm.
