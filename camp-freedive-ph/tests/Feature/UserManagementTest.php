<?php

namespace Tests\Feature;

use App\Models\Coach;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $admin;
    protected User $coach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->coach = User::factory()->create([
            'role' => 'coach',
            'status' => 'active',
        ]);
    }

    public function test_owner_can_provision_new_coach_account_with_credentials_in_session()
    {
        $response = $this->actingAs($this->owner)->post(route('admin.users.store'), [
            'name' => 'Maria Santos',
            'email' => 'maria.santos@test.ph',
            'phone' => '09171112233',
            'role' => 'coach',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('new_user_credentials');

        $creds = session('new_user_credentials');
        $this->assertEquals('Maria Santos', $creds['name']);
        $this->assertEquals('maria.santos@test.ph', $creds['email']);
        $this->assertEquals('coach', $creds['role']);
        $this->assertNotEmpty($creds['temp_password']);

        $newUser = User::where('email', 'maria.santos@test.ph')->first();
        $this->assertNotNull($newUser);
        $this->assertTrue($newUser->must_change_password);
    }

    public function test_admin_can_provision_new_coach_account()
    {
        $response = $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.delacruz@test.ph',
            'phone' => '09170001122',
            'role' => 'coach',
            'temp_password' => 'CustomTemp123!',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $creds = session('new_user_credentials');
        $this->assertEquals('CustomTemp123!', $creds['temp_password']);
    }

    public function test_admin_cannot_provision_admin_or_owner_account()
    {
        $response = $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Fake Admin',
            'email' => 'fake.admin@test.ph',
            'role' => 'admin',
        ]);

        $response->assertSessionHasErrors(['role']);
    }

    public function test_admin_can_delete_coach_account()
    {
        $coachToDelete = User::factory()->create([
            'role' => 'coach',
            'status' => 'active',
        ]);
        Coach::create([
            'user_id' => $coachToDelete->id,
            'full_name' => $coachToDelete->name,
            'email' => $coachToDelete->email,
            'phone' => '09170001122',
            'certification_level' => 'AIDA 4 Master',
            'certification_number' => 'AIDA-12345',
            'certification_expiry' => now()->addYear(),
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->delete(route('admin.users.destroy', $coachToDelete));

        $response->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseMissing('users', ['id' => $coachToDelete->id]);
        $this->assertDatabaseMissing('coaches', ['user_id' => $coachToDelete->id]);
    }

    public function test_admin_cannot_delete_owner_or_admin()
    {
        $response = $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->owner));
        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_themselves()
    {
        $response = $this->actingAs($this->owner)->delete(route('admin.users.destroy', $this->owner));
        $response->assertSessionHas('error', 'You cannot delete your own account.');
    }

    public function test_updating_password_forces_must_change_password_and_flashes_credentials()
    {
        $response = $this->actingAs($this->owner)->put(route('admin.users.update', $this->coach), [
            'name' => $this->coach->name,
            'email' => $this->coach->email,
            'phone' => $this->coach->phone ?? '09170001122',
            'role' => 'coach',
            'status' => 'active',
            'new_password' => 'NewTempResetPass123!',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('new_user_credentials');

        $creds = session('new_user_credentials');
        $this->assertEquals('NewTempResetPass123!', $creds['temp_password']);
        $this->assertTrue($creds['is_reset']);

        $this->coach->refresh();
        $this->assertTrue($this->coach->must_change_password);
    }

    public function test_user_provisioning_supports_filipino_spanish_characters_and_name_components()
    {
        $response = $this->actingAs($this->owner)->post(route('admin.users.store'), [
            'first_name' => 'Maria Ma.',
            'middle_name' => 'Nuñez',
            'last_name' => 'Santos-Concepcion',
            'suffix' => 'Jr.',
            'email' => 'maria.santosconcepcion@test.ph',
            'phone' => '09171112244',
            'role' => 'coach',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $newUser = User::where('email', 'maria.santosconcepcion@test.ph')->first();
        $this->assertNotNull($newUser);
        $this->assertEquals('Maria Ma. Nuñez Santos-Concepcion Jr.', $newUser->name);
    }

    public function test_user_provisioning_with_no_middle_name_toggle()
    {
        $response = $this->actingAs($this->owner)->post(route('admin.users.store'), [
            'first_name' => 'John Christopher Michael',
            'middle_name' => 'ShouldBeIgnored',
            'no_middle_name' => 1,
            'last_name' => 'De la Cruz',
            'suffix' => 'III',
            'email' => 'john.delacruz@test.ph',
            'phone' => '09171112255',
            'role' => 'coach',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $newUser = User::where('email', 'john.delacruz@test.ph')->first();
        $this->assertNotNull($newUser);
        $this->assertEquals('John Christopher Michael De la Cruz III', $newUser->name);
    }

    public function test_user_update_with_filipino_name_conventions()
    {
        $response = $this->actingAs($this->owner)->put(route('admin.users.update', $this->coach), [
            'first_name' => 'Mary-Ann',
            'middle_name' => 'Santo Niño',
            'last_name' => 'Nuñez',
            'suffix' => '',
            'email' => $this->coach->email,
            'phone' => '09170001122',
            'role' => 'coach',
            'status' => 'active',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $this->coach->refresh();
        $this->assertEquals('Mary-Ann Santo Niño Nuñez', $this->coach->name);
    }
}
