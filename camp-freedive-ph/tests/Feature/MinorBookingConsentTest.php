<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PricingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The primary contact must be 18+, participants are 8-85, and participants under 18
 * need a parent or guardian's consent.
 */
class MinorBookingConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PricingRule::query()->forceDelete();
    }

    private function payload(array $participants, array $extra = []): array
    {
        return array_merge([
            'class_type' => 'discovery',
            'start_date' => '2027-03-13',
            'end_date' => '2027-03-14',
            'participants' => $participants,
            'selected_lead_participant' => 0,
            'contact_first_name' => 'Ana',
            'contact_last_name' => 'Cruz',
            'contact_email' => 'ana@example.com',
            'contact_phone' => '09171234567',
            'pickup_option' => 'own',
            'has_agreed_to_terms' => true,
            'confirmation_ack' => true,
            'payment_method' => 'paymongo',
        ], $extra);
    }

    private function person(string $first, int $yearsOld): array
    {
        return [
            'first_name' => $first, 'last_name' => 'Cruz',
            'birthdate' => now()->subYears($yearsOld)->subDays(10)->toDateString(),
            'gender' => 'female', 'swimmer_status' => 'swimmer',
        ];
    }

    private function guardian(): array
    {
        return [
            'guardian_name' => 'Maria Cruz',
            'guardian_relationship' => 'parent',
            'guardian_phone' => '09181234567',
            'guardian_consent' => true,
        ];
    }

    public function test_a_minor_cannot_be_the_primary_contact(): void
    {
        $this->postJson(route('booking.store'), $this->payload([$this->person('Ana', 9)], $this->guardian()))
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_birthdate');

        $this->assertSame(0, Booking::count());
    }

    public function test_a_custom_contact_must_give_an_adult_birthdate(): void
    {
        $base = $this->payload([$this->person('Ana', 30)], ['selected_lead_participant' => 'custom']);

        $this->postJson(route('booking.store'), $base)->assertJsonValidationErrors('contact_birthdate');
        $this->postJson(route('booking.store'), $base + ['contact_birthdate' => now()->subYears(16)->toDateString()])
            ->assertJsonValidationErrors('contact_birthdate');
    }

    public function test_minor_participant_needs_guardian_consent(): void
    {
        $participants = [$this->person('Ana', 40), $this->person('Lia', 12)];

        $this->postJson(route('booking.store'), $this->payload($participants))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guardian_name', 'guardian_relationship', 'guardian_phone', 'guardian_consent']);

        $this->assertSame(0, Booking::count());
    }

    public function test_minor_participant_with_consent_is_booked_and_consent_is_saved(): void
    {
        $participants = [$this->person('Ana', 40), $this->person('Lia', 12)];

        $this->postJson(route('booking.store'), $this->payload($participants, $this->guardian()))
            ->assertOk()
            ->assertJson(['success' => true]);

        $booking = Booking::first();
        $this->assertSame('Maria Cruz', $booking->guardian_name);
        $this->assertSame('parent', $booking->guardian_relationship);
        $this->assertNotNull($booking->guardian_consent_at);
    }

    public function test_adults_only_booking_needs_no_consent(): void
    {
        $this->postJson(route('booking.store'), $this->payload([$this->person('Ana', 30)]))->assertOk();

        $this->assertNull(Booking::first()->guardian_consent_at);
    }

    public function test_age_limit_is_checked_from_the_birthdate(): void
    {
        $this->postJson(route('booking.store'), $this->payload([$this->person('Ana', 30), $this->person('Tot', 5)], $this->guardian()))
            ->assertJsonValidationErrors('participants.1.birthdate');
    }
}
