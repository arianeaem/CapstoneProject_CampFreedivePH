<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\DemandForecast;
use App\Services\DemandForecastService;
use App\Services\PricingRuleEngine;
use App\Support\DemandRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Demand/season rules come from one place, no made-up forecasts,
 * and pricing uses the ML forecast.
 */
class DemandSingleSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DemandForecast::query()->delete();
        Cache::forget('ml_demand_forecast');
    }

    public function test_rules_come_from_the_ml_pipelines_json_file(): void
    {
        $this->assertTrue(DemandRules::rulesLoaded(), 'demand-forecast/demand_thresholds.json was not found');

        $json = json_decode(file_get_contents(config('demand.rules_path')), true);
        $this->assertEquals($json['demand_level']['low_max'], DemandRules::lowMax());
        $this->assertEquals($json['demand_level']['medium_max'], DemandRules::mediumMax());
        $this->assertEquals($json['season']['by_month'], config('demand.season_by_month'));
    }

    public function test_demand_level_boundaries(): void
    {
        $low = DemandRules::lowMax();
        $med = DemandRules::mediumMax();

        $this->assertSame('Low', DemandRules::demandLevel($low));
        $this->assertSame('Medium', DemandRules::demandLevel($low + 0.1));
        $this->assertSame('Medium', DemandRules::demandLevel($med));
        $this->assertSame('High', DemandRules::demandLevel($med + 0.1));
    }

    public function test_all_three_seasons_exist_and_every_month_is_mapped(): void
    {
        $seasons = [];
        for ($m = 1; $m <= 12; $m++) {
            $seasons[] = DemandRules::seasonForMonth($m);
        }
        $this->assertEqualsCanonicalizing(['Peak', 'Shoulder', 'Off-Peak'], array_values(array_unique($seasons)));
    }

    public function test_no_fabricated_forecast_when_ml_has_not_run(): void
    {
        $service = app(DemandForecastService::class);
        $data = $service->getForecastData(true);

        $this->assertSame('no_forecast_yet', $data['source']);
        $this->assertSame([], $data['forecasts']);
        $this->assertNull($service->getStaffingRecommendationForDate(now()->addDays(10)));
    }

    public function test_forecast_rows_are_stored_apart_from_actual_batches(): void
    {
        $batchesBefore = Batch::count();

        DemandForecast::create([
            'forecast_date' => now()->addDays(14)->toDateString(),
            'days_ahead' => 14,
            'predicted_participants' => 22,
            'predicted_bookings' => 9,
            'predicted_revenue_php' => 90000,
            'demand_level' => 'High',
            'season_period' => 'Peak',
            'instructors_needed' => 6,
            'synced_at' => now(),
        ]);

        $this->assertSame($batchesBefore, Batch::count(), 'a forecast must never create an actual batch');
        $this->assertSame(1, DemandForecast::count());
    }

    public function test_pricing_uses_forecast_demand_and_season(): void
    {
        $date = '2027-03-13'; // Saturday, Peak month
        DemandForecast::create([
            'forecast_date' => $date,
            'days_ahead' => 160,
            'predicted_participants' => 25,
            'predicted_bookings' => 10,
            'predicted_revenue_php' => 100000,
            'demand_level' => 'High',
            'season_period' => 'Peak',
            'instructors_needed' => 7,
            'synced_at' => now(),
        ]);
        Cache::forget('ml_demand_forecast');

        $engine = app(PricingRuleEngine::class);
        $this->assertSame('high', $engine->getDemandForDate($date));
        $this->assertSame('peak', $engine->getSeasonForDate($date));
        $this->assertGreaterThanOrEqual(0, $engine->getLeadTimeDays($date));
    }

    public function test_pricing_uses_the_weekly_forecast_row_near_a_weekend_date(): void
    {
        // Forecast rows are weekly (any weekday); a Saturday camp date should still be covered.
        DemandForecast::create([
            'forecast_date' => '2027-03-10', // Wednesday
            'days_ahead' => 150,
            'predicted_participants' => 6,
            'predicted_bookings' => 3,
            'predicted_revenue_php' => 20000,
            'demand_level' => 'Low',
            'season_period' => 'Peak',
            'instructors_needed' => 2,
            'synced_at' => now(),
        ]);
        Cache::forget('ml_demand_forecast');

        $row = app(DemandForecastService::class)->getForecastForDate('2027-03-13');
        $this->assertNotNull($row);
        $this->assertSame('Low', $row['demand_level']);

        $this->assertNull(app(DemandForecastService::class)->getForecastForDate('2027-04-20'));
    }

    public function test_demand_forecast_module_is_a_separate_page(): void
    {
        $owner = \App\Models\User::where('role', 'owner')->first() ?? \App\Models\User::factory()->create([
            'role' => 'owner',
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($owner)->get(route('admin.demand.index'));
        $response->assertOk();
        $response->assertSee('Demand Forecast');
        $response->assertSee('No forecast yet.');
    }
}
