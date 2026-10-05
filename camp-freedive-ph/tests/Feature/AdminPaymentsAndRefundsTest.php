<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPaymentsAndRefundsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_admin_can_access_payments_index(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $response = $this->get('/admin/payments');
        $response->assertStatus(200);
        $response->assertSee('Payments & Refunds');
        $response->assertSee('PAYM-20260831-GCASH-9821');
    }

    public function test_coach_is_forbidden_from_payments_module(): void
    {
        $coach = User::where('email', 'coach.jose@campfreedive.ph')->first();
        $this->actingAs($coach);

        $response = $this->get('/admin/payments');
        $response->assertStatus(403);
    }

    public function test_admin_can_record_manual_payment_and_reduce_balance(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $booking = Booking::where('booking_number', 'CFP-2026-1001')->first();
        $initialBalance = $booking->balance_amount;

        $response = $this->post('/admin/payments', [
            'booking_id' => $booking->id,
            'amount' => 3000.00,
            'payment_method' => 'cash',
            'payment_type' => 'balance_settlement',
            'transaction_id' => 'MANUAL-CASH-TEST-001',
            'notes' => 'Collected in cash at dive camp',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'transaction_id' => 'MANUAL-CASH-TEST-001',
            'amount' => 3000.00,
            'status' => 'completed',
        ]);

        $this->assertEquals($initialBalance - 3000.00, $booking->fresh()->balance_amount);
        $this->assertDatabaseHas('payment_status_logs', [
            'new_status' => 'completed',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_admin_can_approve_refund_and_execute_paymongo(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $refundRequest = RefundRequest::where('status', 'pending')->first();
        $payment = $refundRequest->payment;
        $booking = $refundRequest->booking;

        $response = $this->post("/admin/payments/refunds/{$refundRequest->id}/approve", [
            'notes' => '100% refund approved per camp policy',
        ]);

        $response->assertRedirect();
        $this->assertEquals('approved', $refundRequest->fresh()->status);
        $this->assertEquals('refunded', $payment->fresh()->status);
        $this->assertEquals('cancelled_by_guest', $booking->fresh()->status);
        $this->assertNotNull($payment->fresh()->paymongo_refund_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'REFUND_APPROVED_AND_EXECUTED',
        ]);
    }

    public function test_admin_can_reject_refund_request(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $refundRequest = RefundRequest::where('status', 'pending')->first();
        $payment = $refundRequest->payment;
        $booking = $refundRequest->booking;

        $response = $this->post("/admin/payments/refunds/{$refundRequest->id}/reject", [
            'notes' => 'Refund request rejected. Customer is outside the policy refund window.',
        ]);

        $response->assertRedirect();
        $this->assertEquals('rejected', $refundRequest->fresh()->status);
        $this->assertEquals('completed', $payment->fresh()->status);
        $this->assertEquals('confirmed', $booking->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'REFUND_REJECTED',
        ]);
    }

    public function test_admin_can_forfeit_payment_with_standard_reason(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $refundRequest = RefundRequest::where('status', 'pending')->first();
        $payment = $refundRequest->payment;

        $response = $this->post("/admin/payments/refunds/{$refundRequest->id}/forfeit", [
            'forfeit_reason' => 'cancellation_outside_policy_window',
            'notes' => 'Deposit forfeited due to < 1 week cancellation notice.',
        ]);

        $response->assertRedirect();
        $this->assertEquals('forfeited', $refundRequest->fresh()->status);
        $this->assertEquals('forfeited', $payment->fresh()->status);
        $this->assertTrue($payment->fresh()->is_forfeited);
        $this->assertEquals('cancellation_outside_policy_window', $payment->fresh()->forfeit_reason);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'PAYMENT_FORFEITED',
        ]);
    }
}
