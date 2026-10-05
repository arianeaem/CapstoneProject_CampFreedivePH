<?php

namespace Tests\Feature;

use App\Mail\AccountRemovedMail;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Coach;
use App\Models\CoachAvailability;
use App\Models\ParticipantAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
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

    public function test_removing_coach_archives_account_keeps_data_and_emails_them()
    {
        Mail::fake();

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

        $futureOpenDay = CoachAvailability::create(['coach_id' => $coachToDelete->id, 'date' => now()->addDays(20)->toDateString(), 'status' => 'available']);

        $response = $this->actingAs($this->admin)->delete(route('admin.users.destroy', $coachToDelete));

        $response->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', ['id' => $coachToDelete->id, 'status' => 'archived']);
        $this->assertDatabaseHas('coaches', ['user_id' => $coachToDelete->id, 'status' => 'inactive']);
        $this->assertEquals('unavailable', $futureOpenDay->fresh()->status);
        Mail::assertSent(AccountRemovedMail::class, fn ($mail) => $mail->hasTo($coachToDelete->email));

        // A removed account can no longer use the portal
        $this->actingAs($coachToDelete->fresh())->get('/coach')->assertRedirect(route('login'));
    }

    protected function coachWithUpcomingStudent(): User
    {
        $coach = User::factory()->create(['role' => 'coach', 'status' => 'active']);
        $batch = Batch::create([
            'name' => 'Upcoming Batch', 'batch_code' => 'BATCH-UP-01',
            'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(11)->toDateString(),
            'status' => 'confirmed',
        ]);
        $booking = Booking::create([
            'batch_id' => $batch->id, 'user_id' => $this->owner->id, 'booking_number' => 'BK-' . uniqid(), 'pin' => '1234',
            'class_type' => 'discovery', 'status' => 'confirmed', 'total_amount' => 4250,
            'contact_name' => 'Lead', 'contact_email' => 'lead@example.com', 'contact_phone' => '09123456789',
            'start_date' => $batch->start_date, 'end_date' => $batch->end_date,
        ]);
        $participant = BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Student One', 'age' => 20, 'price_per_person' => 4250]);
        ParticipantAssignment::create([
            'participant_id' => $participant->id, 'booking_id' => $booking->id, 'coach_id' => $coach->id, 'batch_id' => $batch->id,
            'dive_date' => $batch->start_date, 'assigned_by' => $this->owner->id, 'assigned_at' => now(), 'status' => 'assigned',
        ]);

        return $coach;
    }

    public function test_coach_with_upcoming_students_cannot_be_removed_until_reassigned()
    {
        Mail::fake();
        $coach = $this->coachWithUpcomingStudent();

        $this->actingAs($this->owner)->delete(route('admin.users.destroy', $coach))
            ->assertSessionHas('blocked_coach', fn ($b) => $b['batches'][0]['students'] === 1);

        $this->assertDatabaseHas('users', ['id' => $coach->id, 'status' => 'active']);
        Mail::assertNothingSent();

        // After the student is reassigned, removal goes through
        ParticipantAssignment::where('coach_id', $coach->id)->update(['status' => 'reassigned']);
        $this->actingAs($this->owner)->delete(route('admin.users.destroy', $coach))->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', ['id' => $coach->id, 'status' => 'archived']);
    }

    public function test_coach_with_upcoming_students_cannot_be_deactivated()
    {
        $coach = $this->coachWithUpcomingStudent();

        $this->actingAs($this->owner)->patch(route('admin.users.toggle_status', $coach))
            ->assertSessionHas('blocked_coach');

        $this->assertDatabaseHas('users', ['id' => $coach->id, 'status' => 'active']);
    }

    public function test_removed_account_can_be_restored()
    {
        Mail::fake();
        $coach = User::factory()->create(['role' => 'coach', 'status' => 'active']);
        $this->actingAs($this->owner)->delete(route('admin.users.destroy', $coach));
        $this->assertDatabaseHas('users', ['id' => $coach->id, 'status' => 'archived']);

        $this->actingAs($this->owner)->patch(route('admin.users.toggle_status', $coach))->assertSessionHas('success');
        $this->assertDatabaseHas('users', ['id' => $coach->id, 'status' => 'active']);
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
