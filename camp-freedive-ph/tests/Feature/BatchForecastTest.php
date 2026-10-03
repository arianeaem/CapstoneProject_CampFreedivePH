<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchDemandForecast;
use App\Models\DemandForecast;
use App\Services\DemandForecastService;
use App\Services\PricingRuleEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Phase 1: the forecast is made per REAL scheduled batch, stored apart from actual data,
 * rolled up per month, and used by Dynamic Pricing for that batch's date.
 */
class BatchForecastTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        return (string) config('services.ml.token');
    }

    private function makeBatch(string $code, int $daysAhead, string $status = 'confirmed'): Batch
    {
        return Batch::create([
            'name' => $code,
            'batch_code' => $code,
            'start_date' => now()->addDays($daysAhead),
            'end_date' => now()->addDays($daysAhead + 1),
            'status' => $status,
            'lifecycle_status' => $status,
        ]);
    }

    private function batchRow(string $code, string $date, float $pax, string $level = 'High'): array
    {
        return [
            'batch_id' => null,
            'batch_code' => $code,
            'batch_date' => $date,
            'days_to_start' => 10,
            'booked_so_far' => 4,
            'capacity' => 45,
            'predicted_participants' => $pax,
            'predicted_bookings' => 8.0,
            'predicted_revenue_php' => 60000,
            'lower_bound' => max(0, $pax - 5),
            'upper_bound' => $pax + 5,
            'predicted_fill_rate' => round($pax / 45, 4),
            'demand_level' => $level,
            'season_period' => 'Peak',
            'adjusted_for_booked' => false,
            'data_basis' => 'limited_history',
            'model_version' => 'test-v1',
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    private function sync(array $batchRows): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->token())
            ->postJson('/api/v1/ml/sync-forecast', [
                'forecasts' => [[
                    'forecast_date' => now()->addDays(7)->toDateString(),
                    'days_ahead' => 7,
                    'predicted_participants' => 12,
                    'predicted_bookings' => 5,
                    'predicted_revenue_php' => 50000,
                    'demand_level' => 'Low',
                    'season_period' => 'Shoulder',
                    'instructors_needed' => 3,
                ]],
                'batch_forecasts' => $batchRows,
                'metadata' => [
                    'model_version' => 'test-v1',
                    'data_basis' => 'limited_history',
                    'training_batches' => 85,
                    'baseline_comparison' => [
                        'participant_count' => ['model' => ['MAE' => 9.4], 'naive' => ['MAE' => 9.0], 'seasonal_naive' => ['MAE' => 8.1]],
                        'test_batches' => 13,
                        'model_validated' => false,
                    ],
                ],
            ]);
    }

    public function test_scheduled_batches_endpoint_requires_token(): void
    {
        $this->getJson('/api/v1/ml/scheduled-batches')->assertStatus(401);
    }

    public function test_scheduled_batches_lists_only_real_upcoming_batches(): void
    {
        $this->makeBatch('B-UPCOMING', 10, 'confirmed');
        $this->makeBatch('B-PAST', -10, 'completed');
        $this->makeBatch('B-CANCELLED', 12, 'cancelled_by_camp');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token())
            ->getJson('/api/v1/ml/scheduled-batches');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('batch_code')->all();
        $this->assertSame(['B-UPCOMING'], $codes);
        $this->assertSame(45, $response->json('data.0.capacity'));
        $this->assertSame(0, $response->json('data.0.booked_so_far'));
    }

    public function test_sync_stores_per_batch_rows_apart_from_actual_batches(): void
    {
        $batchesBefore = Batch::count();
        $date = now()->addDays(10)->toDateString();

        $this->sync([$this->batchRow('B-1', $date, 24)])->assertOk();

        $this->assertSame(1, BatchDemandForecast::count());
        $this->assertSame($batchesBefore, Batch::count(), 'a forecast must never create an actual batch');
        $this->assertSame('High', BatchDemandForecast::first()->demand_level);
    }

    public function test_new_sync_replaces_old_per_batch_rows(): void
    {
        $date = now()->addDays(10)->toDateString();
        $this->sync([$this->batchRow('B-1', $date, 24), $this->batchRow('B-2', now()->addDays(17)->toDateString(), 8, 'Low')])->assertOk();
        $this->assertSame(2, BatchDemandForecast::count());

        $this->sync([$this->batchRow('B-1', $date, 18, 'Medium')])->assertOk();
        $this->assertSame(1, BatchDemandForecast::count());
    }

    public function test_invalid_demand_level_is_derived_from_the_shared_rules(): void
    {
        $date = now()->addDays(10)->toDateString();
        $row = $this->batchRow('B-1', $date, 30, 'nonsense');

        $this->sync([$row])->assertOk();

        $this->assertSame('High', BatchDemandForecast::first()->demand_level);
    }

    public function test_monthly_rollup_is_the_sum_of_the_batches(): void
    {
        $month = now()->addMonths(1)->startOfMonth();
        $rows = [
            $this->batchRow('B-1', $month->copy()->addDays(2)->toDateString(), 20),
            $this->batchRow('B-2', $month->copy()->addDays(9)->toDateString(), 10),
        ];
        $this->sync($rows)->assertOk();

        $service = app(DemandForecastService::class);
        $rollup = $service->getBatchMonthlyRollup($service->getBatchForecasts());

        $this->assertCount(1, $rollup);
        $this->assertSame(2, $rollup[0]['batches']);
        $this->assertEquals(30.0, $rollup[0]['predicted_participants']);
        $this->assertEquals(16.0, $rollup[0]['predicted_bookings']);
        $this->assertEquals(15.0, $rollup[0]['avg_participants_per_batch']);
    }

    public function test_pricing_uses_the_batch_forecast_for_that_batch_date(): void
    {
        $date = now()->addDays(10)->toDateString();

        // The weekly outlook says Low for this very date, the per-batch forecast says High.
        DemandForecast::create([
            'forecast_date' => $date,
            'days_ahead' => 10,
            'predicted_participants' => 6,
            'predicted_bookings' => 2,
            'predicted_revenue_php' => 20000,
            'demand_level' => 'Low',
            'season_period' => 'Shoulder',
            'instructors_needed' => 2,
            'synced_at' => now(),
        ]);
        BatchDemandForecast::create([
            'batch_code' => 'B-1',
            'batch_date' => $date,
            'predicted_participants' => 28,
            'predicted_bookings' => 10,
            'predicted_revenue_php' => 90000,
            'demand_level' => 'High',
            'season_period' => 'Peak',
            'synced_at' => now(),
        ]);
        Cache::forget('ml_demand_forecast');

        $engine = app(PricingRuleEngine::class);
        $this->assertSame('high', $engine->getDemandForDate($date));
        $this->assertSame('peak', $engine->getSeasonForDate($date));
    }

    public function test_demand_page_shows_the_per_batch_table_and_limited_history_warning(): void
    {
        $date = now()->addDays(10)->toDateString();
        $this->sync([$this->batchRow('B-SHOWN', $date, 24)])->assertOk();

        $owner = User::where('role', 'owner')->first() ?? User::factory()->create([
            'role' => 'owner',
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($owner)->get(route('admin.demand.index'));
        $response->assertOk();
        $response->assertSee('Forecast per scheduled batch');
        $response->assertSee('B-SHOWN');
        $response->assertSee('Limited history');
        $response->assertSee('Not better than simple baselines');
        // Phase 1: one forecast view only. The weekly outlook stays in Reports & Analytics.
        $response->assertDontSee('90-day outlook');
    }
}
