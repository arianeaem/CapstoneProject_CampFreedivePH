<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InternalAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_login_page_renders_successfully(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertSee('Email Address');
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

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'LOGIN_SUCCESS',
            'user_id' => auth()->id(),
        ]);
    }

    public function test_admin_login_redirects_to_admin_dashboard(): void
    {
        $response = $this->post('/login', [
            'email' => 'admin@campfreedive.ph',
            'password' => 'Password123!',
        ]);

        $response->assertRedirect('/admin');
        $this->assertAuthenticated();
        $this->assertEquals('admin', auth()->user()->role);
    }

    public function test_coach_login_redirects_to_coach_portal(): void
    {
        $response = $this->post('/login', [
            'email' => 'coach.jose@campfreedive.ph',
            'password' => 'Password123!',
        ]);

        $response->assertRedirect('/coach');
        $this->assertAuthenticated();
        $this->assertEquals('coach', auth()->user()->role);
    }

    public function test_deactivated_account_is_blocked_with_custom_message(): void
    {
        $response = $this->post('/login', [
            'email' => 'coach.angelo@campfreedive.ph',
            'password' => 'Password123!',
        ]);

        $response->assertSessionHas('error', 'This account has been deactivated. Please contact the camp owner.');
        $this->assertGuest();

        // Verify audit log for deactivated block
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'LOGIN_DEACTIVATED_BLOCKED',
        ]);
    }

    public function test_user_with_temporary_password_is_forced_to_change_password(): void
    {
        $tempCoach = User::factory()->create([
            'email' => 'coach.temp@campfreedive.ph',
            'password' => Hash::make('TempPass123!'),
            'role' => 'coach',
            'status' => 'active',
            'must_change_password' => true,
        ]);

        $response = $this->post('/login', [
            'email' => 'coach.temp@campfreedive.ph',
            'password' => 'TempPass123!',
        ]);

        $response->assertRedirect(route('password.force_change'));
        $this->assertAuthenticated();

        // Attempting to access coach dashboard while must_change_password is true redirects back to force change
        $dashResponse = $this->get('/coach');
        $dashResponse->assertRedirect(route('password.force_change'));

        // Update password
        $updateResponse = $this->post('/force-password-change', [
            'current_password' => 'TempPass123!',
            'password' => 'NewSecurePass123!',
            'password_confirmation' => 'NewSecurePass123!',
        ]);

        $updateResponse->assertRedirect(route('coach.dashboard'));
        $this->assertFalse(auth()->user()->fresh()->must_change_password);
        $this->assertTrue(Hash::check('NewSecurePass123!', auth()->user()->fresh()->password));
    }

    public function test_role_authorization_middleware_restricts_access(): void
    {
        $coach = User::where('email', 'coach.jose@campfreedive.ph')->first();
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $owner = User::where('email', 'owner@campfreedive.ph')->first();

        // Coach cannot access admin dashboard, but can access coach portal
        $this->actingAs($coach);
        $this->get('/admin')->assertStatus(403);
        $coachResponse = $this->get('/coach')->assertStatus(200);

        // Admin can access admin dashboard, but NOT audit logs (Owner exclusive)
        $this->actingAs($admin);
        $adminResponse = $this->get('/admin')->assertStatus(200);
        $adminResponse->assertSee('Upcoming Weekend Batches');
        $this->get('/admin/settings/audit-logs')->assertStatus(403);

        // Owner can access both executive and operations views on /owner
        $this->actingAs($owner);
        $ownerExecResponse = $this->get('/owner')->assertStatus(200);
        $ownerExecResponse->assertSee('Gross Collected Revenue');
        $ownerExecResponse->assertSee('Executive Analytics');

        $ownerOpsResponse = $this->get('/owner?view=operations')->assertStatus(200);
        $ownerOpsResponse->assertSee('Upcoming Weekend Batches');

        $this->get('/owner/settings/audit-logs')->assertStatus(200);
        $this->get('/owner/settings/users')->assertStatus(200);

        // Owner visiting /admin/settings/audit-logs redirects to /owner/settings/audit-logs
        $this->get('/admin/settings/audit-logs')->assertRedirect('/owner/settings/audit-logs');
    }

    public function test_owner_can_provision_new_coach_and_toggle_status(): void
    {
        $owner = User::where('email', 'owner@campfreedive.ph')->first();
        $this->actingAs($owner);

        // Provision a new Coach
        $createResponse = $this->post('/owner/settings/users', [
            'name' => 'Coach New Recruit',
            'email' => 'coach.new@campfreedive.ph',
            'phone' => '0912 345 6789',
            'role' => 'coach',
            'temp_password' => 'TempPass999!',
        ]);

        $createResponse->assertRedirect(route('owner.users.index'));
        $this->assertDatabaseHas('users', [
            'email' => 'coach.new@campfreedive.ph',
            'role' => 'coach',
            'status' => 'active',
            'must_change_password' => true,
        ]);

        $newCoach = User::where('email', 'coach.new@campfreedive.ph')->first();

        // Toggle status to inactive
        $toggleResponse = $this->patch("/owner/settings/users/{$newCoach->id}/toggle-status");
        $toggleResponse->assertRedirect();
        $this->assertEquals('inactive', $newCoach->fresh()->status);

        // Edit profile
        $editResponse = $this->put("/owner/settings/users/{$newCoach->id}", [
            'name' => 'Coach Updated Name',
            'email' => 'coach.new@campfreedive.ph',
            'phone' => '0999 888 7777',
            'role' => 'coach',
            'status' => 'active',
        ]);
        $editResponse->assertRedirect(route('owner.users.index'));
        $this->assertEquals('Coach Updated Name', $newCoach->fresh()->name);
        $this->assertEquals('active', $newCoach->fresh()->status);
    }

    public function test_wrong_password_shows_explicit_error_and_validation_feedback(): void
    {
        $response = $this->from('/login')->post('/login', [
            'email' => 'owner@campfreedive.ph',
            'password' => 'WrongPassword123!',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHas('error', 'The password you entered is incorrect. Please check your password or click "Forgot password?".');
        $response->assertSessionHasErrors(['password']);
        $this->assertGuest();

        // Follow redirect to verify single clean alert banner renders on login page
        $followResponse = $this->get('/login');
        $followResponse->assertStatus(200);
        $followResponse->assertSee('The password you entered is incorrect. Please check your password or click &quot;Forgot password?&quot;.', false);
    }

    public function test_unknown_email_shows_invalid_credentials_error(): void
    {
        $response = $this->from('/login')->post('/login', [
            'email' => 'unknown.user@campfreedive.ph',
            'password' => 'SomePassword123!',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHas('error', 'These credentials do not match our records. Please check your email address and password.');
        $response->assertSessionHasErrors(['email']);
        $this->assertGuest();
    }
}
