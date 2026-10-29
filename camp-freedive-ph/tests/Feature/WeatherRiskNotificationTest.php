<?php

namespace Tests\Feature;

use App\Mail\AdminOperationalNotificationMail;
use App\Mail\WeatherRiskNoticeMail;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\BookingPolicyEngine;
use App\Services\WeatherRiskNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WeatherRiskNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // 6:00 PM the evening before a 6:30 AM dive = about 12 hours to go (inside the 18-hour window)
        Carbon::setTestNow(Carbon::parse('2026-10-10 18:00', 'Asia/Manila'));

        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'email' => 'admin@example.com']);
        $this->batch = Batch::create([
            'name' => 'Weekend Batch', 'batch_code' => 'WX-01',
            'start_date' => '2026-10-11', 'end_date' => '2026-10-12',
            'status' => 'confirmed', 'lifecycle_status' => 'confirmed',
        ]);
    }

    protected function booking(string $status, string $email): Booking
    {
        return Booking::create([
            'batch_id' => $this->batch->id, 'booking_number' => 'BK-' . uniqid(), 'pin' => '1234',
            'class_type' => 'discovery', 'status' => $status, 'total_amount' => 4250, 'downpayment_amount' => 2125,
            'balance_amount' => 2125, 'contact_name' => 'Guest ' . $status, 'contact_email' => $email, 'contact_phone' => '09123456789',
            'start_date' => '2026-10-11', 'end_date' => '2026-10-12',
        ]);
    }

    public function test_critical_risk_within_18_hours_emails_guests_their_options_and_admins_once(): void
    {
        $confirmed = $this->booking('confirmed', 'guest@example.com');
        $this->booking('pending_downpayment', 'unpaid@example.com');
        $this->booking('cancelled_by_guest', 'cancelled@example.com');

        app(WeatherRiskNotifier::class)->handle($this->batch, 'Critical Risk');

        // B6: notifications go through the durable outbox, not direct Mail::send
        $this->assertDatabaseHas('checkout_notifications', [
            'booking_id' => $confirmed->id,
            'kind' => 'guest_weather_critical',
        ]);
        // Admin alert also in outbox
        $this->assertDatabaseHas('checkout_notifications', [
            'kind' => 'staff_admin_alert',
        ]);
        $guestRows = \App\Models\CheckoutNotification::where('kind', 'guest_weather_critical')->count();
        $this->assertEquals(1, $guestRows);

        // The hourly re-check must not create a duplicate outbox row (insertOrIgnore + event_key dedup)
        app(WeatherRiskNotifier::class)->handle($this->batch, 'Critical Risk');
        $this->assertEquals(1, \App\Models\CheckoutNotification::where('kind', 'guest_weather_critical')->count());
    }

    public function test_high_risk_within_18_hours_is_a_heads_up_without_refund_offer(): void
    {
        $this->booking('confirmed', 'guest@example.com');

        app(WeatherRiskNotifier::class)->handle($this->batch, 'High Risk');

        $this->assertDatabaseHas('checkout_notifications', [
            'kind' => 'guest_weather_high',
        ]);
        $this->assertDatabaseHas('checkout_notifications', [
            'kind' => 'staff_admin_alert',
        ]);
    }

    public function test_no_notices_more_than_18_hours_before_the_dive(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 09:00', 'Asia/Manila')); // about 21.5 hours to go
        $this->booking('confirmed', 'guest@example.com');

        app(WeatherRiskNotifier::class)->handle($this->batch, 'Critical Risk');

        Mail::assertNothingSent();
    }

    public function test_official_critical_rating_unlocks_free_reschedule_and_full_refund_in_manage_booking(): void
    {
        $booking = $this->booking('confirmed', 'guest@example.com');
        $this->batch->update(['risk_classification' => 'critical_risk']);

        $policy = app(BookingPolicyEngine::class)->evaluate($booking->fresh());

        $this->assertTrue($policy['is_force_majeure']);
        $this->assertTrue($policy['reschedule_allowed']);
        $this->assertTrue($policy['cancel_allowed']);
        $this->assertEquals(2125.0, (float) $policy['calculated_refund']);
    }
}
