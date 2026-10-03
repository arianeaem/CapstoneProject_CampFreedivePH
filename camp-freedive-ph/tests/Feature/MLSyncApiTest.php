<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\DemandForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MLSyncApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_training_data_requires_token(): void
    {
        $response = $this->getJson('/api/v1/ml/training-data');
        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized: Invalid or missing ML API token.',
            ]);
    }

    public function test_training_data_exports_with_valid_token(): void
    {
        $token = config('services.ml.token');
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/ml/training-data');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'exported_at',
                'total_records',
                'data',
            ]);
    }

    public function test_sync_forecast_requires_token(): void
    {
        $response = $this->postJson('/api/v1/ml/sync-forecast', [
            'forecasts' => [],
        ]);

        $response->assertStatus(401);
    }

    public function test_sync_forecast_imports_predictions(): void
    {
        $token = config('services.ml.token');
        $payload = [
            'horizon_summaries' => [
                '7_day' => ['batches' => 1, 'participants' => 19, 'revenue' => 20810, 'peak_instructors' => 5],
                '30_day' => ['batches' => 4, 'participants' => 87, 'revenue' => 117428, 'peak_instructors' => 6],
                '60_day' => ['batches' => 8, 'participants' => 196, 'revenue' => 296724, 'peak_instructors' => 8],
                '90_day' => ['batches' => 12, 'participants' => 306, 'revenue' => 446372, 'peak_instructors' => 8],
            ],
            'forecasts' => [
                [
                    'forecast_date' => '2025-11-29',
                    'days_ahead' => 7,
                    'predicted_participants' => 18.8,
                    'predicted_bookings' => 8.2,
                    'predicted_revenue_php' => 20810.0,
                    'demand_level' => 'Medium',
                    'season_period' => 'Off-Peak',
                    'instructors_needed' => 5,
                ],
                [
                    'forecast_date' => '2025-12-06',
                    'days_ahead' => 14,
                    'predicted_participants' => 22.6,
                    'predicted_bookings' => 9.5,
                    'predicted_revenue_php' => 30599.0,
                    'demand_level' => 'Medium',
                    'season_period' => 'Shoulder',
                    'instructors_needed' => 6,
                ],
            ],
            'monthly_classifications' => [
                [
                    'month' => 'November 2025',
                    'monthly_average' => 18.8,
                    'overall_mean' => 20.7,
                    'standard_deviation' => 2.7,
                    'upper_threshold' => 23.4,
                    'lower_threshold' => 18.0,
                    'classification' => 'Shoulder',
                ],
                [
                    'month' => 'December 2025',
                    'monthly_average' => 22.6,
                    'overall_mean' => 20.7,
                    'standard_deviation' => 2.7,
                    'upper_threshold' => 23.4,
                    'lower_threshold' => 18.0,
                    'classification' => 'Shoulder',
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/ml/sync-forecast', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'synced_count' => 2,
            ])
            ->assertJsonStructure([
                'status',
                'synced_count',
                'monthly_classifications',
            ]);

        $this->assertEquals(2, DemandForecast::count());
        $this->assertDatabaseHas('demand_forecasts', [
            'forecast_date' => '2025-11-29',
            'predicted_participants' => 18.8,
            'predicted_bookings' => 8.2,
            'demand_level' => 'Medium',
            'instructors_needed' => 5,
        ]);
    }

    public function test_get_forecast_endpoint_returns_monthly_classifications(): void
    {
        $token = config('services.ml.token');

        // Prime database with forecast
        $this->test_sync_forecast_imports_predictions();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/ml/forecast');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'synced_at',
                    'source',
                    'horizon_summaries',
                    'monthly_horizons',
                    'monthly_classifications' => [
                        '*' => [
                            'month',
                            'monthly_average',
                            'overall_mean',
                            'standard_deviation',
                            'upper_threshold',
                            'lower_threshold',
                            'classification',
                        ],
                    ],
                    'forecasts',
                ],
            ]);
    }

    public function test_training_data_exports_only_completed_batches_and_excludes_future_confirmed(): void
    {
        $token = config('services.ml.token');

        // Create completed past batch
        $completedBatch = Batch::create([
            'name' => 'Past Completed Batch',
            'batch_code' => 'BATCH-TEST-COMPLETED',
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(9),
            'status' => 'completed',
            'lifecycle_status' => 'completed',
            'completed_at' => now()->subDays(9),
        ]);

        // Create future confirmed batch
        $futureConfirmedBatch = Batch::create([
            'name' => 'Future Confirmed Batch',
            'batch_code' => 'BATCH-TEST-FUTURE',
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(11),
            'status' => 'confirmed',
            'lifecycle_status' => 'confirmed',
            'completed_at' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/ml/training-data');

        $response->assertStatus(200);

        $batchIds = collect($response->json('data'))->pluck('batch_id')->all();

        $this->assertContains($completedBatch->id, $batchIds, 'Completed batch must be included');
        $this->assertNotContains($futureConfirmedBatch->id, $batchIds, 'Future confirmed batch must be excluded');
    }
}

