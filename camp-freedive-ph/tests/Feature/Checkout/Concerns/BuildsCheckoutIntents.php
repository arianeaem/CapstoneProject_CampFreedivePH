<?php

namespace Tests\Feature\Checkout\Concerns;

use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Gateways\PayMongoGateway;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/** Synthetic checkout intents, staff users and a capacity-6 date for the Batch 2 completion tests. */
trait BuildsCheckoutIntents
{
    protected const DATE = '2026-12-19';
    protected const CONTACT_EMAIL = 'casey.fixture@example.test';

    private int $intentSeq = 0;

    protected function setUpCheckoutWorld(): void
    {
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00', 'Asia/Manila'));
        config(['checkout.reconcile_enabled' => true, 'checkout.finalization_lock_wait_seconds' => 0]);
        SystemSetting::create(['group' => 'camp_operations', 'key' => 'camp_operations.max_batch_capacity', 'value' => '6', 'type' => 'integer']);
        $this->mock(WeatherForecastService::class, fn ($mock) => $mock->shouldReceive('assessBatch')->andReturn([]));
    }

    protected function checkoutIntent(int $ageMinutes = 30, array $overrides = []): CheckoutIntent
    {
        $n = ++$this->intentSeq;
        $created = now()->subMinutes($ageMinutes);
        $intent = CheckoutIntent::create(array_merge([
            'reference' => 'CHK-BC' . str_pad((string) $n, 10, '0', STR_PAD_LEFT),
            'status' => CheckoutIntent::STATUS_PENDING, 'start_date' => self::DATE, 'end_date' => '2026-12-20',
            'pax_count' => 2, 'class_type' => 'discovery', 'pickup_option' => 'own', 'currency' => 'PHP',
            'total_amount' => 9200, 'downpayment_amount' => 4000,
            'paymongo_checkout_session_id' => "cs_test_bc_{$n}", 'hold_expires_at' => $created->copy()->addMinutes(15),
            'payload' => [
                'booking' => ['class_type' => 'discovery', 'is_certified_diver' => false, 'start_date' => self::DATE, 'end_date' => '2026-12-20', 'pickup_option' => 'own', 'pickup_location' => null, 'boat_dive' => false],
                'contact' => ['name' => 'Casey Fixture', 'email' => self::CONTACT_EMAIL, 'phone' => '09170000001', 'facebook' => null],
                'guardian' => [],
                'participants' => array_map(fn ($i) => ['name' => "Synthetic Diver {$i}", 'birthdate' => null, 'gender' => 'prefer_not_to_say', 'age' => 30,
                    'health_condition' => null, 'swimmer_status' => 'swimmer', 'price_per_person' => 4250.0], [1, 2]),
                'pricing' => ['pricing_version' => 1, 'subtotal' => 8500.0, 'adjustments' => [],
                    'fees' => ['lgu' => 600.0, 'environmental' => 100.0, 'carpool' => 0.0, 'boat_dive' => 0.0], 'total_amount' => 9200, 'downpayment_amount' => 4000],
            ],
        ], $overrides));
        DB::table('checkout_intents')->where('id', $intent->id)->update(['created_at' => $created, 'updated_at' => $created]);

        return $intent->fresh();
    }

    protected function staffUser(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'must_change_password' => false, 'email' => "{$role}." . uniqid() . '@example.test']);
    }

    /** Another confirmed booking taking $pax of the date's 6 places. */
    protected function fillDate(int $pax): void
    {
        $booking = Booking::create(['booking_number' => 'CFP-2026-BCF' . (++$this->intentSeq), 'pin' => '0000', 'contact_name' => 'Other Guest',
            'contact_email' => 'other@example.test', 'contact_phone' => '09170000002', 'class_type' => 'discovery', 'start_date' => self::DATE,
            'end_date' => '2026-12-20', 'status' => 'confirmed', 'total_amount' => 1, 'downpayment_amount' => 1, 'balance_amount' => 0]);
        foreach (range(1, $pax) as $i) {
            $booking->participants()->create(['name' => "Other Diver {$i}", 'age' => 30, 'price_per_person' => 4250]);
        }
    }

    protected function signedCheckoutUrl(CheckoutIntent $intent, string $route = 'checkout.return'): string
    {
        return PayMongoGateway::signedIntentUrl($route, $intent);
    }

    protected function paidWebhookEvent(CheckoutIntent $intent, string $eventId): array
    {
        return ['data' => ['id' => $eventId, 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'data' => [
            'id' => $intent->paymongo_checkout_session_id, 'type' => 'checkout_session',
            'attributes' => ['metadata' => ['checkout_intent_id' => (string) $intent->id, 'checkout_reference' => $intent->reference]]]]]];
    }
}
