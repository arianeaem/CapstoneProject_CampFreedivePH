<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class OwnerPortalRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_owner_login_redirects_to_owner_dashboard(): void
    {
        $response = $this->post('/login', [
            'email' => 'owner@campfreedive.ph',
            'password' => 'Password123!',
        ]);

        $response->assertRedirect('/owner');
        $this->assertAuthenticated();
        $this->assertEquals('owner', auth()->user()->role);
    }

    public function test_owner_can_access_all_owner_portal_modules(): void
    {
        $owner = User::where('email', 'owner@campfreedive.ph')->first();
        $this->actingAs($owner);

        // Dashboard
        $this->get('/owner')->assertStatus(200);

        // Bookings
        $this->get('/owner/bookings')->assertStatus(200);

        // Batches
        $this->get('/owner/batches')->assertStatus(200);

        // Safety Monitoring / Weather
        $this->get('/owner/weather')->assertStatus(200);

        // Dynamic Pricing
        $this->get('/owner/pricing')->assertStatus(200)->assertSee('Dynamic Pricing');

        // Payments & Refunds
        $this->get('/owner/payments')->assertStatus(200);

        // Coaches & Schedules
        $this->get('/owner/coaches')->assertStatus(200);

        // User Management
        $this->get('/owner/settings/users')->assertStatus(200);

        // Audit Logs (Owner Exclusive)
        $this->get('/owner/settings/audit-logs')->assertStatus(200);
    }

    public function test_owner_accessing_admin_prefix_is_automatically_redirected_to_owner(): void
    {
        $owner = User::where('email', 'owner@campfreedive.ph')->first();
        $this->actingAs($owner);

        $this->get('/admin')->assertRedirect('/owner');
        $this->get('/admin/pricing')->assertRedirect('/owner/pricing');
        $this->get('/admin/bookings')->assertRedirect('/owner/bookings');
        $this->get('/admin/batches')->assertRedirect('/owner/batches');
        $this->get('/admin/weather')->assertRedirect('/owner/weather');
        $this->get('/admin/payments')->assertRedirect('/owner/payments');
        $this->get('/admin/coaches')->assertRedirect('/owner/coaches');
        $this->get('/admin/settings/users')->assertRedirect('/owner/settings/users');
        $this->get('/admin/settings/audit-logs')->assertRedirect('/owner/settings/audit-logs');
    }

    public function test_admin_accessing_owner_prefix_is_automatically_redirected_to_admin(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $this->get('/owner')->assertRedirect('/admin');
        $this->get('/owner/pricing')->assertRedirect('/admin/pricing');
        $this->get('/owner/bookings')->assertRedirect('/admin/bookings');

        // Admin cannot access audit logs on owner prefix
        $this->get('/owner/settings/audit-logs')->assertStatus(403);
    }

    public function test_coach_cannot_access_owner_or_admin_portals(): void
    {
        $coach = User::where('email', 'coach.jose@campfreedive.ph')->first();
        $this->actingAs($coach);

        $this->get('/owner')->assertStatus(403);
        $this->get('/owner/pricing')->assertStatus(403);
        $this->get('/admin')->assertStatus(403);
        $this->get('/admin/pricing')->assertStatus(403);
    }

    public function test_role_aware_url_generator_maps_route_names_correctly(): void
    {
        $owner = User::where('email', 'owner@campfreedive.ph')->first();
        Auth::login($owner);

        $this->assertStringContainsString('/owner/dynamic-pricing', route('admin.pricing.index'));
        $this->assertStringContainsString('/owner/dynamic-pricing', route('owner.pricing.index'));
        $this->assertStringContainsString('/owner', route('admin.dashboard'));

        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        Auth::login($admin);

        $this->assertStringContainsString('/admin/dynamic-pricing', route('admin.pricing.index'));
        $this->assertStringContainsString('/admin/dynamic-pricing', route('owner.pricing.index'));
        $this->assertStringContainsString('/admin', route('admin.dashboard'));
    }
}
