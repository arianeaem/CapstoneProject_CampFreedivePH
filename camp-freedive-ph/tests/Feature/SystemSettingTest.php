<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemSettingTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $admin;
    protected User $coach;
    protected SystemSettingService $settingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'name' => 'Antonio Owner',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->admin = User::factory()->create([
            'name' => 'Maria Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->coach = User::factory()->create([
            'name' => 'Jose Coach',
            'role' => 'coach',
            'status' => 'active',
        ]);

        $this->seed(SystemSettingsSeeder::class);
        $this->settingService = app(SystemSettingService::class);
    }

    public function test_owner_can_view_master_settings_directory()
    {
        $response = $this->actingAs($this->owner)->get(route('owner.settings.index'));

        $response->assertStatus(200);
        $response->assertSee('Settings');
        $response->assertSee('Program Pricing', false);
        $response->assertSee('Camp Policies', false);
        $response->assertSee('System Administration', false);
    }

    public function test_admin_and_coach_cannot_access_settings_pages()
    {
        $responseAdmin = $this->actingAs($this->admin)->get(route('admin.settings.index'));
        $responseAdmin->assertStatus(403);

        $responseCoach = $this->actingAs($this->coach)->get('/owner/settings');
        $responseCoach->assertStatus(403);
    }

    public function test_owner_can_view_and_update_programs_pricing_and_inclusions()
    {
        $viewResponse = $this->actingAs($this->owner)->get(route('owner.settings.programs'));
        $viewResponse->assertStatus(200);
        $viewResponse->assertSee('Discovery Freedive');
        $viewResponse->assertSee('Fun Dive');
        $viewResponse->assertSee('Skills Refinement');

        $payload = [
            'base_price_discovery' => 4500.00,
            'base_price_fundive_cert' => 2700.00,
            'base_price_fundive_noncert' => 3500.00,
            'base_price_refinement' => 4300.00,
            'dynamic_pricing_cap_percent' => 25.00,
            'discovery_inclusions' => "2 open water dives\n3 meals\nCustom Gear",
            'discovery_exclusions' => "Van Transportation\nBoat dive",
            'fundive_inclusions' => "2 open water dives\nSafety coach",
            'fundive_exclusions' => "Transportation",
            'refinement_inclusions' => "Depth clinic\nPhotos",
            'refinement_exclusions' => "Transportation",
        ];

        $updateResponse = $this->actingAs($this->owner)->put(route('owner.settings.programs.update'), $payload);
        $updateResponse->assertRedirect();
        $updateResponse->assertSessionHas('success');

        $this->assertEquals(4500.00, $this->settingService->get('program_pricing.base_price_discovery'));
        $this->assertEquals(2700.00, $this->settingService->get('program_pricing.base_price_fundive_cert'));
        $this->assertEquals(3500.00, $this->settingService->get('program_pricing.base_price_fundive_noncert'));
        $this->assertEquals(['2 open water dives', '3 meals', 'Custom Gear'], $this->settingService->get('program_pricing.discovery_inclusions'));

        // Verify LandingController reflects these changes
        $landingResponse = $this->get('/');
        $landingResponse->assertStatus(200);
        $landingResponse->assertSee('₱4,500');
        $landingResponse->assertSee('Custom Gear');
    }

    public function test_owner_can_view_and_update_downpayment_deposits()
    {
        $viewResponse = $this->actingAs($this->owner)->get(route('owner.settings.deposits'));
        $viewResponse->assertStatus(200);
        $viewResponse->assertSee('Deposit Rules');

        $payload = [
            'downpayment_carpool' => 3500.00,
            'downpayment_own_transpo' => 2500.00,
        ];

        $updateResponse = $this->actingAs($this->owner)->put(route('owner.settings.deposits.update'), $payload);
        $updateResponse->assertRedirect();
        $updateResponse->assertSessionHas('success');

        $this->assertEquals(3500.00, $this->settingService->get('program_pricing.downpayment_carpool'));
        $this->assertEquals(2500.00, $this->settingService->get('program_pricing.downpayment_own_transpo'));
    }

    public function test_owner_can_view_and_update_addons_and_pickup_locations()
    {
        $viewResponse = $this->actingAs($this->owner)->get(route('owner.settings.addons'));
        $viewResponse->assertStatus(200);
        $viewResponse->assertSee('Add-on Services', false);

        $payload = [
            'carpool_fee_per_head' => 1300.00,
            'boat_dive_fee_per_head' => 700.00,
            'lgu_tourism_pass_fee' => 350.00,
            'environmental_fee' => 75.00,
            'pickup_locations' => [
                [
                    'id' => 'hub_bgc',
                    'name' => 'BGC High Street - 3:15 AM',
                    'time' => '3:15 AM',
                    'address' => '7th Ave BGC, Taguig City',
                ],
                [
                    'id' => 'hub_makati',
                    'name' => 'Makati Ayala Center - 3:45 AM',
                    'time' => '3:45 AM',
                    'address' => 'Ayala Center, Makati City',
                ],
            ],
        ];

        $updateResponse = $this->actingAs($this->owner)->put(route('owner.settings.addons.update'), $payload);
        $updateResponse->assertRedirect();
        $updateResponse->assertSessionHas('success');

        $this->assertEquals(1300.00, $this->settingService->get('addons.carpool_fee_per_head'));
        $this->assertEquals(700.00, $this->settingService->get('addons.boat_dive_fee_per_head'));

        $locations = $this->settingService->get('addons.pickup_locations');
        $this->assertCount(2, $locations);
        $this->assertEquals('BGC High Street - 3:15 AM', $locations[0]['name']);
    }

    public function test_owner_can_update_operations_and_cancellation_policies()
    {
        // Operations
        $opResponse = $this->actingAs($this->owner)->put(route('owner.settings.operations.update'), [
            'max_batch_capacity' => 50,
            'coach_student_ratio' => 5,
            'min_coaches_per_batch' => 3,
        ]);
        $opResponse->assertRedirect();
        $this->assertEquals(50, $this->settingService->get('camp_operations.max_batch_capacity'));

        // Cancellation
        $cancelResponse = $this->actingAs($this->owner)->put(route('owner.settings.cancellation.update'), [
            'full_refund_threshold_days' => 21,
            'reschedule_only_threshold_days' => 14,
        ]);
        $cancelResponse->assertRedirect();
        $this->assertEquals(21, $this->settingService->get('booking_cancellation.full_refund_threshold_days'));
    }

    public function test_cancellation_validation_rejects_invalid_day_windows()
    {
        $response = $this->actingAs($this->owner)->put(route('owner.settings.cancellation.update'), [
            'full_refund_threshold_days' => 7,
            'reschedule_only_threshold_days' => 14, // Greater than refund threshold
        ]);

        $response->assertSessionHasErrors(['full_refund_threshold_days']);
    }
}
