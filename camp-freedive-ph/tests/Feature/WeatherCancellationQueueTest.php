<?php

namespace Tests\Feature;

use App\Mail\BatchWeatherCancellationMail;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Payment;
use App\Models\User;
use App\Services\WeatherForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WeatherCancellationQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelling_batch_dispatches_queued_weather_cancellation_mailables(): void
    {
        Mail::fake();

        $operator = User::factory()->create([
            'role' => 'admin',
            'email' => 'operator@example.com',
        ]);

        $batch = Batch::create([
            'name' => 'Batch Storm Cancellation',
            'batch_code' => 'BATCH-STORM-01',
            'start_date' => '2026-11-07',
            'end_date' => '2026-11-08',
            'max_capacity' => 45,
            'status' => 'open',
            'lifecycle_status' => 'scheduled',
        ]);

        $booking1 = Booking::create([
            'booking_number' => 'CFP-2026-STORM1',
            'pin' => '1111',
            'batch_id' => $batch->id,
            'class_type' => 'discovery',
            'start_date' => '2026-11-07',
            'end_date' => '2026-11-08',
            'pickup_option' => 'own',
            'contact_name' => 'Guest Alpha',
            'contact_email' => 'alpha@example.com',
            'contact_phone' => '09171112222',
            'status' => 'confirmed',
            'total_amount' => 4250,
            'downpayment_amount' => 3000,
            'balance_amount' => 1250,
        ]);

        BookingParticipant::create([
            'booking_id' => $booking1->id,
            'name' => 'Guest Alpha',
            'age' => 25,
            'price_per_person' => 4250,
        ]);

        Payment::create([
            'booking_id' => $booking1->id,
            'payment_method' => 'paymongo',
            'transaction_id' => 'TXN-ALPHA',
            'amount' => 3000,
            'fee_amount' => 0,
            'net_amount' => 3000,
            'payment_type' => 'downpayment',
            'status' => 'completed',
        ]);

        $booking2 = Booking::create([
            'booking_number' => 'CFP-2026-STORM2',
            'pin' => '2222',
            'batch_id' => $batch->id,
            'class_type' => 'fundive',
            'start_date' => '2026-11-07',
            'end_date' => '2026-11-08',
            'pickup_option' => 'carpool',
            'contact_name' => 'Guest Beta',
            'contact_email' => 'beta@example.com',
            'contact_phone' => '09173334444',
            'status' => 'confirmed',
            'total_amount' => 5450,
            'downpayment_amount' => 3000,
            'balance_amount' => 2450,
        ]);

        BookingParticipant::create([
            'booking_id' => $booking2->id,
            'name' => 'Guest Beta',
            'age' => 28,
            'price_per_person' => 3300,
        ]);

        Payment::create([
            'booking_id' => $booking2->id,
            'payment_method' => 'paymongo',
            'transaction_id' => 'TXN-BETA',
            'amount' => 3000,
            'fee_amount' => 0,
            'net_amount' => 3000,
            'payment_type' => 'downpayment',
            'status' => 'completed',
        ]);

        $cancellationReason = 'PAGASA TCWS Signal No. 2 Gale Warning & Dangerous Swells';

        $forecastService = app(WeatherForecastService::class);
        $sentCount = $forecastService->cancelBatchWithRefundsAndNotifications($batch, $cancellationReason, $operator);

        $this->assertEquals(2, $sentCount);

        // Assert Batch and Booking statuses
        $batch->refresh();
        $this->assertEquals('cancelled_by_camp', $batch->status);
        $this->assertEquals('cancelled_by_camp', $batch->lifecycle_status);

        $booking1->refresh();
        $this->assertEquals('cancelled_by_camp', $booking1->status);

        $booking2->refresh();
        $this->assertEquals('cancelled_by_camp', $booking2->status);

        // Assert 100% force majeure refund requests created
        $this->assertDatabaseHas('refund_requests', [
            'booking_id' => $booking1->id,
            'requested_by' => 'camp_force_majeure',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('refund_requests', [
            'booking_id' => $booking2->id,
            'requested_by' => 'camp_force_majeure',
            'status' => 'pending',
        ]);

        // Assert NotificationLogs created
        $this->assertDatabaseHas('notification_logs', [
            'booking_id' => $booking1->id,
            'recipient_email' => 'alpha@example.com',
            'channel' => 'email',
        ]);

        $this->assertDatabaseHas('notification_logs', [
            'booking_id' => $booking2->id,
            'recipient_email' => 'beta@example.com',
            'channel' => 'email',
        ]);

        // Assert Mail was queued (not synchronously sent)
        Mail::assertQueued(BatchWeatherCancellationMail::class, function ($mail) use ($booking1, $cancellationReason) {
            return $mail->hasTo('alpha@example.com')
                && $mail->booking->id === $booking1->id
                && $mail->cancellationReason === $cancellationReason;
        });

        Mail::assertQueued(BatchWeatherCancellationMail::class, function ($mail) use ($booking2, $cancellationReason) {
            return $mail->hasTo('beta@example.com')
                && $mail->booking->id === $booking2->id
                && $mail->cancellationReason === $cancellationReason;
        });
    }

    public function test_admin_weather_safety_controller_cancel_endpoint_queues_emails(): void
    {
        Mail::fake();

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'must_change_password' => false,
            'email' => 'admin@example.com',
        ]);

        $batch = Batch::create([
            'name' => 'Batch Controller Test',
            'batch_code' => 'BATCH-CTRL-01',
            'start_date' => '2026-11-14',
            'end_date' => '2026-11-15',
            'max_capacity' => 45,
            'status' => 'open',
            'lifecycle_status' => 'scheduled',
        ]);

        $booking = Booking::create([
            'booking_number' => 'CFP-2026-CTRL1',
            'pin' => '3333',
            'batch_id' => $batch->id,
            'class_type' => 'discovery',
            'start_date' => '2026-11-14',
            'end_date' => '2026-11-15',
            'pickup_option' => 'own',
            'contact_name' => 'Guest Charlie',
            'contact_email' => 'charlie@example.com',
            'contact_phone' => '09175556666',
            'status' => 'confirmed',
            'total_amount' => 4250,
            'downpayment_amount' => 3000,
            'balance_amount' => 1250,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.weather.cancel', ['batch' => $batch->id]), [
            'cancellation_reason' => 'Severe coastal squall and 3.2m wave conditions',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('success');

        Mail::assertQueued(BatchWeatherCancellationMail::class, function ($mail) {
            return $mail->hasTo('charlie@example.com');
        });
    }
}
