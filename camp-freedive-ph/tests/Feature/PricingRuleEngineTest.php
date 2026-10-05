<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DemandForecast;
use App\Models\PricingRule;
use App\Models\User;
use App\Services\PricingRuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingRuleEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear rules before each test
        PricingRule::query()->forceDelete();
    }

    public function test_pricing_rule_engine_seasons_follow_the_demand_rules(): void
    {
        $engine = app(PricingRuleEngine::class);

        // Seasons are derived from the 553 actual records (config/demand.php), not a hardcoded calendar.
        $this->assertEquals('peak', $engine->getSeasonForDate('2027-03-15'));
        $this->assertEquals('peak', $engine->getSeasonForDate('2027-06-12'));
        $this->assertEquals('shoulder', $engine->getSeasonForDate('2027-02-13'));
        $this->assertEquals('shoulder', $engine->getSeasonForDate('2026-12-15'));
        $this->assertEquals('off_peak', $engine->getSeasonForDate('2027-07-17'));
        $this->assertEquals('off_peak', $engine->getSeasonForDate('2026-10-10'));

        for ($m = 1; $m <= 12; $m++) {
            $date = sprintf('2027-%02d-15', $m);
            $this->assertEquals(
                \App\Support\DemandRules::seasonSlug(\App\Support\DemandRules::seasonForMonth($m)),
                $engine->getSeasonForDate($date)
            );
        }
    }

    public function test_pricing_rule_engine_applies_seasonality_and_clamps_within_cap(): void
    {
        $engine = app(PricingRuleEngine::class);

        // Create an active 10% peak surcharge rule
        PricingRule::create([
            'name' => 'Test Peak Surcharge',
            'rule_type' => 'seasonality',
            'condition_value' => 'peak',
            'applies_to' => 'all',
            'adjustment_type' => 'increase',
            'adjustment_method' => 'percentage',
            'adjustment_value' => 10.00,
            'priority' => 1,
            'status' => 'active',
        ]);

        // Evaluate Discovery on Mar 15 (Peak season per the actual-data rules)
        $quote = $engine->evaluate('discovery', '2027-03-15', false, 2);

        $this->assertEquals(4250.00, $quote['base_price_per_pax']);
        $this->assertEquals(425.00, $quote['delta_per_pax']); // +10% of 4,250
        $this->assertEquals(4675.00, $quote['adjusted_price_per_pax']);
        $this->assertEquals(9350.00, $quote['subtotal']); // 4,675 * 2
        $this->assertCount(1, $quote['adjustments']);
        $this->assertFalse($quote['was_clamped']);
    }

    public function test_stacked_rules_are_clamped_to_thirty_percent(): void
    {
        $engine = app(PricingRuleEngine::class);

        // Create two 20% increase rules (Total 40% -> should clamp to 30%)
        PricingRule::create([
            'name' => 'Rule A',
            'rule_type' => 'seasonality',
            'condition_value' => 'peak',
            'applies_to' => 'all',
            'adjustment_type' => 'increase',
            'adjustment_method' => 'percentage',
            'adjustment_value' => 20.00,
            'priority' => 1,
            'status' => 'active',
        ]);

        PricingRule::create([
            'name' => 'Rule B',
            'rule_type' => 'seasonality',
            'condition_value' => 'peak',
            'applies_to' => 'all',
            'adjustment_type' => 'increase',
            'adjustment_method' => 'percentage',
            'adjustment_value' => 20.00,
            'priority' => 2,
            'status' => 'active',
        ]);

        $quote = $engine->evaluate('discovery', '2027-03-15', false, 1);

        // Base 4,250 * 0.30 max cap = +1,275.00
        $this->assertEquals(4250.00, $quote['base_price_per_pax']);
        $this->assertEquals(1275.00, $quote['delta_per_pax']);
        $this->assertEquals(5525.00, $quote['adjusted_price_per_pax']);
        $this->assertTrue($quote['was_clamped']);
    }

    public function test_fundive_certified_vs_non_certified_base_prices(): void
    {
        $engine = app(PricingRuleEngine::class);

        // Certified Fundive base is ₱2,500
        $this->assertEquals(2500.00, $engine->getBasePrice('fundive', true));

        // Non-certified Fundive base is ₱3,300
        $this->assertEquals(3300.00, $engine->getBasePrice('fundive', false));
    }

    public function test_api_pricing_quote_endpoint(): void
    {
        PricingRule::create([
            'name' => 'Test Off-Peak Discount',
            'rule_type' => 'seasonality',
            'condition_value' => 'off_peak',
            'applies_to' => 'all',
            'adjustment_type' => 'decrease',
            'adjustment_method' => 'percentage',
            'adjustment_value' => 10.00,
            'priority' => 1,
            'status' => 'active',
        ]);

        $response = $this->postJson(route('api.pricing.quote'), [
            'class_type' => 'discovery',
            'start_date' => '2027-07-17',
            'participants_count' => 2,
        ]);

        $response->assertOk();
        $response->assertJson([
            'season' => 'off_peak',
            'base_price_per_pax' => 4250.00,
            'adjusted_price_per_pax' => 3825.00, // 4250 - 425 (10%)
            'subtotal' => 7650.00,
        ]);
    }

    public function test_admin_can_crud_pricing_rules(): void
    {
        $owner = User::where('role', 'owner')->first() ?? User::factory()->create([
            'role' => 'owner',
            'status' => 'active',
            'must_change_password' => false,
        ]);

        // 1. Index
        $response = $this->actingAs($owner)->get(route('admin.pricing.index'));
        $response->assertOk();
        $response->assertSee('Dynamic Pricing');

        // 2. Create
        $response = $this->actingAs($owner)->post(route('admin.pricing.store'), [
            'name' => 'Admin Promo Test',
            'rule_type' => 'seasonality',
            'condition_value' => 'peak',
            'applies_to' => 'discovery',
            'adjustment_type' => 'decrease',
            'adjustment_method' => 'fixed',
            'adjustment_value' => 500.00,
            'priority' => 1,
            'status' => 'active',
        ]);

        $response->assertRedirect(route('admin.pricing.index'));
        $this->assertDatabaseHas('pricing_rules', [
            'name' => 'Admin Promo Test',
            'adjustment_value' => 500.00,
        ]);

        $rule = PricingRule::where('name', 'Admin Promo Test')->first();

        // 3. Toggle Status
        $response = $this->actingAs($owner)->patch(route('admin.pricing.toggle_status', $rule));
        $this->assertEquals('inactive', $rule->fresh()->status);

        // 4. Soft Delete
        $response = $this->actingAs($owner)->delete(route('admin.pricing.destroy', $rule));
        $response->assertRedirect(route('admin.pricing.index'));
        $this->assertSoftDeleted('pricing_rules', ['id' => $rule->id]);
    }

    public function test_completed_booking_records_dynamic_price_adjustments(): void
    {
        // 10% peak surge
        $rule = PricingRule::create([
            'name' => 'Peak Season Test Bump',
            'rule_type' => 'seasonality',
            'condition_value' => 'peak',
            'applies_to' => 'all',
            'adjustment_type' => 'increase',
            'adjustment_method' => 'percentage',
            'adjustment_value' => 10.00,
            'priority' => 1,
            'status' => 'active',
        ]);

        $payload = [
            'class_type' => 'discovery',
            'start_date' => '2027-03-13',
            'end_date' => '2027-03-14',
            'participants' => [
                ['first_name' => 'Alice', 'last_name' => 'Test', 'name' => 'Alice Test', 'age' => 25, 'health_condition' => 'None', 'swimmer_status' => 'beginner'],
                ['first_name' => 'Bob', 'last_name' => 'Test', 'name' => 'Bob Test', 'age' => 26, 'health_condition' => 'None', 'swimmer_status' => 'intermediate'],
            ],
            'contact_first_name' => 'Alice',
            'contact_last_name' => 'Test',
            'contact_name' => 'Alice Test',
            'contact_email' => 'alice.test@example.com',
            'contact_phone' => '09171234567',
            'pickup_option' => 'carpool',
            'pickup_location' => 'Shell Tiendesitas - 3:00 AM',
            'boat_dive' => true,
            'has_agreed_to_terms' => true,
            'confirmation_ack' => true,
            'payment_method' => 'paymongo',
        ];

        $response = $this->postJson(route('booking.store'), $payload);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $booking = Booking::where('contact_email', 'alice.test@example.com')->first();
        $this->assertNotNull($booking);

        // Verify that priceAdjustments relationship has the record
        $this->assertCount(1, $booking->priceAdjustments);
        $adjustment = $booking->priceAdjustments->first();

        $this->assertEquals($rule->id, $adjustment->pricing_rule_id);
        $this->assertEquals('Peak Season Test Bump', $adjustment->rule_name);
        $this->assertEquals(4250.00, (float)$adjustment->base_price);
        $this->assertEquals(425.00, (float)$adjustment->adjustment_amount);
        $this->assertEquals(4675.00, (float)$adjustment->adjusted_price);

        // Check participant price
        $this->assertEquals(4675.00, (float)$booking->participants->first()->price_per_person);

        // Subtotal = 4,675 * 2 = 9,350
        $this->assertEquals(9350.00, (float)$booking->subtotal);
    }

    public function test_pricing_engine_uses_ml_demand_forecast_for_future_unbooked_batches(): void
    {
        DemandForecast::query()->delete();
        \Illuminate\Support\Facades\Cache::forget('ml_demand_forecast');
        $engine = app(PricingRuleEngine::class);
        $futureDate = '2027-08-20';

        // 1. Without ML forecast or bookings, demand is low
        $this->assertEquals('low', $engine->getDemandForDate($futureDate));

        // 2. Save an ML forecast that says High demand (38 pax)
        DemandForecast::create([
            'forecast_date' => $futureDate,
            'days_ahead' => 65,
            'predicted_participants' => 38,
            'predicted_revenue_php' => 182400.00,
            'demand_level' => 'High',
            'season_period' => 'Peak',
            'instructors_needed' => 10,
            'synced_at' => now(),
        ]);

        \Illuminate\Support\Facades\Cache::forget('ml_demand_forecast');

        // 3. Now the demand should be 'high'
        $this->assertEquals('high', $engine->getDemandForDate($futureDate));
        $this->assertEquals('peak', $engine->getSeasonForDate($futureDate));

        // 4. Create an active high demand pricing rule
        $rule = PricingRule::create([
            'name' => 'High Demand Yield Surcharge',
            'rule_type' => 'demand',
            'condition_value' => 'high',
            'applies_to' => 'all',
            'adjustment_type' => 'increase',
            'adjustment_method' => 'percentage',
            'adjustment_value' => 15.00,
            'priority' => 1,
            'status' => 'active',
        ]);

        $quote = $engine->evaluate('discovery', $futureDate, false, 1);

        $this->assertEquals('high', $quote['demand']);
        $this->assertEquals('ml_predictive', $quote['forecast_source']);
        $this->assertEquals(38, $quote['predicted_participants']);
        $this->assertEquals(4250.00, $quote['base_price_per_pax']);
        $this->assertEquals(637.50, $quote['delta_per_pax']); // +15% of 4250
        $this->assertEquals(4887.50, $quote['adjusted_price_per_pax']);
        $this->assertCount(1, $quote['adjustments']);
    }
}
