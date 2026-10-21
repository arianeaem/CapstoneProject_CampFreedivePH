<?php

namespace Tests\Unit;

use App\Models\Booking;
use App\Models\CheckoutIntent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stage A (tests first): the future CheckoutIntent model and its database constraints.
 *
 * Expected to fail until the feature exists. Future contracts:
 *  - model App\Models\CheckoutIntent on table `checkout_intents`, `payload` cast as `encrypted:array`;
 *  - unique: reference, paymongo_checkout_session_id, paymongo_payment_id, booking_id (all nullable except reference);
 *  - `bookings.checkout_intent_id`: nullable and unique (one official booking per intent; legacy bookings have none).
 *
 * Unique checks expect UniqueConstraintViolationException specifically, and only after a first row
 * was saved, so a missing table can never make them pass.
 */
class CheckoutIntentModelTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function payload(): array
    {
        return [
            'contact' => ['name' => 'Casey Fixture', 'email' => 'casey.fixture@example.test', 'phone' => '09170000001'],
            'participants' => [
                ['name' => 'Synthetic Diver', 'birthdate' => '1999-04-01', 'health_condition' => 'Synthetic mild asthma note', 'swimmer_status' => 'swimmer'],
            ],
        ];
    }

    private function intent(array $overrides = []): CheckoutIntent
    {
        $n = ++$this->seq;

        return CheckoutIntent::create(array_merge([
            'reference' => sprintf('CHK-TEST%04d', $n),
            'status' => 'pending',
            'start_date' => '2026-12-12',
            'end_date' => '2026-12-13',
            'pax_count' => 1,
            'class_type' => 'discovery',
            'pickup_option' => 'own',
            'currency' => 'PHP',
            'total_amount' => 4250.00,
            'downpayment_amount' => 2000.00,
            'payload' => $this->payload(),
            'hold_expires_at' => now()->addMinutes(15),
        ], $overrides));
    }

    private function booking(?int $checkoutIntentId, string $number): Booking
    {
        // forceFill: the test does not depend on $fillable, only on the column and its constraint
        $booking = (new Booking())->forceFill([
            'booking_number' => $number,
            'pin' => '0000',
            'contact_name' => 'Casey Fixture',
            'contact_email' => 'casey.fixture@example.test',
            'contact_phone' => '09170000001',
            'class_type' => 'discovery',
            'start_date' => '2026-12-12',
            'end_date' => '2026-12-13',
            'status' => 'confirmed',
            'total_amount' => 4250.00,
            'downpayment_amount' => 2000.00,
            'balance_amount' => 2250.00,
            'checkout_intent_id' => $checkoutIntentId,
        ]);
        $booking->save();

        return $booking;
    }

    public function test_sensitive_payload_is_encrypted_at_rest(): void
    {
        $intent = $this->intent();

        $raw = (string) DB::table('checkout_intents')->where('id', $intent->id)->value('payload');
        $this->assertNotSame('', $raw);
        foreach (['Casey Fixture', 'casey.fixture@example.test', '09170000001', 'Synthetic mild asthma note', '1999-04-01'] as $plain) {
            $this->assertStringNotContainsString($plain, $raw, 'Checkout payload must be encrypted in the database.');
        }
    }

    public function test_payload_decrypts_through_the_model(): void
    {
        $intent = $this->intent();

        $this->assertSame($this->payload(), CheckoutIntent::findOrFail($intent->id)->payload);
    }

    public function test_reference_is_unique(): void
    {
        $this->intent(['reference' => 'CHK-DUPLICATE']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->intent(['reference' => 'CHK-DUPLICATE']);
    }

    public function test_paymongo_checkout_session_id_is_unique(): void
    {
        $this->intent(['paymongo_checkout_session_id' => 'cs_test_same']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->intent(['paymongo_checkout_session_id' => 'cs_test_same']);
    }

    public function test_paymongo_payment_id_is_unique_but_may_be_empty_before_payment(): void
    {
        // Unpaid intents have no payment id yet; many may coexist
        $this->intent(['paymongo_payment_id' => null]);
        $this->intent(['paymongo_payment_id' => null]);
        $this->intent(['paymongo_payment_id' => 'pay_test_same']);
        $this->assertSame(3, CheckoutIntent::count());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->intent(['paymongo_payment_id' => 'pay_test_same']);
    }

    public function test_only_one_official_booking_can_be_linked_to_a_checkout_intent(): void
    {
        $intent = $this->intent(['status' => 'finalized']);
        $this->booking($intent->id, 'CFP-2026-UNIT1');
        $this->assertSame(1, Booking::where('checkout_intent_id', $intent->id)->count());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->booking($intent->id, 'CFP-2026-UNIT2');
    }

    public function test_an_official_booking_can_be_linked_from_only_one_checkout_intent(): void
    {
        $booking = $this->booking(null, 'CFP-2026-UNIT3');
        $this->intent(['status' => 'finalized', 'booking_id' => $booking->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->intent(['status' => 'finalized', 'booking_id' => $booking->id]);
    }

    public function test_legacy_bookings_and_unpaid_intents_may_have_no_link(): void
    {
        // Existing (legacy) bookings were never created from an intent
        $this->booking(null, 'CFP-2026-LEGACY1');
        $this->booking(null, 'CFP-2026-LEGACY2');
        $this->assertSame(2, Booking::whereNull('checkout_intent_id')->count());

        // Unpaid intents have no booking yet
        $this->intent(['booking_id' => null]);
        $this->intent(['booking_id' => null]);
        $this->assertSame(2, CheckoutIntent::whereNull('booking_id')->count());
    }
}
