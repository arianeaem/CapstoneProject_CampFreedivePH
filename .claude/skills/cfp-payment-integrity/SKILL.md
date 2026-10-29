---
name: cfp-payment-integrity
description: Use when working on PayMongo payments, manual/staff-recorded payments, payment exceptions, refund approval, or the refund ledger in Camp Freedive PH. Protects verified-vs-unverified payment distinctions and prevents inventing financial policy.
---

# cfp-payment-integrity

## Purpose
Protect correctness of payment verification, exception handling, and refund approval — financial records here are meant to be immutable and auditable.

## When to use
Any change touching `app/Services/{Checkout,Payment,Refunds,Gateways}`, `app/Models/{PaymentException*,ProviderPaymentIdentity,BookingRefund,RefundRequest,RefundAttempt}`, or their controllers/tests.

## When not to use
Pure UI/display changes that don't touch payment state or amounts.

## Relevant source references
- Verified payment vs exception: `CheckoutWebhookHandler`, `CheckoutReconciler`, `PaymentVerifier`, `VerifiedPayment`, `PaymentVerificationResult` (all in `app/Services/Checkout/`)
- Exceptions/allocation: `app/Services/Payment/{PaymentExceptionService,ExceptionResolutionService,GuestChoiceService}`; models `PaymentException`, `PaymentExceptionAllocationAttempt`, `PaymentExceptionChoice`, `PaymentExceptionEvent`, `ProviderPaymentIdentity`, `ProviderIdentityConflict`
- Manual payments: `app/Services/Payment/ManualPaymentService.php`, `app/Http/Controllers/Admin/PaymentController.php`
- Receipts: `app/Services/Payment/{ReceiptRecorder,ReceiptOutcome,ReceiptBackfillPlanner}`
- Refund ledger/approval: `app/Services/Refunds/{RefundLedger,RefundCaseService,RefundExecutor,RefundGuard,FinancialTotals}`; `app/Services/Payment/{RefundService,ExceptionRefundService,AllocationRefused}`; `app/Http/Controllers/Admin/{RefundController,RefundCaseController}`
- Tests: `tests/Feature/Payments/*`, `tests/Feature/Refunds/*`, `tests/Feature/Checkout/CheckoutReturnAndWebhookTest.php`, `tests/Feature/PayMongoIntegrationTest.php`

## Business invariants (do not invent new ones)
- A payment is "verified" only through the reconciler/webhook path — manual payments are staff-attested, tracked separately with provenance, and must not be silently treated as equivalent to verified PayMongo payments.
- `paid_unallocated` / `paid_again` are exception states requiring explicit resolution, not auto-correction.
- Refund **approval** (Owner/Admin) is distinct from refund **payout** — approving a refund must never trigger automatic execution/forfeiture.
- Financial ledger entries are immutable; corrections are new entries, not edits.
- Idempotency matters: webhook/reconciliation handlers must tolerate replay without double-crediting or double-refunding.
- Shared refundable balance across a guest's payments must be respected — don't double-count or double-reserve.

## Safe workflow
1. Read the relevant service + its test file before changing behavior; this module has layered exception/refund policy already shipped across several batches (3-I, 3-II) — check for existing handling before adding new branches.
2. Never make a real PayMongo API call from a test or dev script — use the existing fakes/mocks the test suite already provides.
3. Any refund-related change must preserve: approval ≠ payout, no auto-forfeiture, no auto-execution.
4. Run the specific `tests/Feature/{Payments,Refunds}` suite most related to your change, then the full financial regression set, before calling it done.

## Stop conditions
- A change would require inventing a financial policy not already present in the codebase/tests (e.g., a new refund-eligibility rule) — surface it as a question, don't decide it.
- A change would make refund approval trigger automatic payout.
- A real PayMongo call would be needed to proceed.

## Expected output
Change summary naming which invariant(s) above it touches, and which test files were run to confirm no regression.
