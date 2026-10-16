<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfilePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'Synthetic-Pass-1';
    private const NEW_PASSWORD = 'Synthetic-New-Pass-9';

    private function makeStaff(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'email' => "profile.{$role}@example.test",
            'status' => 'active',
            'must_change_password' => false,
            'password' => Hash::make(self::OLD_PASSWORD),
        ]);
    }

    private function validPayload(array $changes = []): array
    {
        return array_merge([
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $changes);
    }

    public function test_owner_admin_and_coach_can_change_their_password(): void
    {
        foreach (['owner', 'admin', 'coach'] as $role) {
            $user = $this->makeStaff($role);

            $this->actingAs($user)
                ->post(route('profile.password.update'), $this->validPayload())
                ->assertRedirect(route('profile.show'))
                ->assertSessionHas('password_changed');

            $this->assertTrue(
                Hash::check(self::NEW_PASSWORD, $user->fresh()->password)
            );

            $this->assertFalse(
                Hash::check(self::OLD_PASSWORD, $user->fresh()->password)
            );

            $this->assertDatabaseHas('audit_logs', [
                'action' => 'PASSWORD_CHANGED',
                'user_id' => $user->id,
            ]);
        }
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = $this->makeStaff('owner');

        $this->actingAs($user)
            ->post(route('profile.password.update'), $this->validPayload([
                'current_password' => 'Incorrect-Pass-1',
            ]))
            ->assertSessionHasErrors(['current_password'], null, 'changePassword');

        $this->assertTrue(
            Hash::check(self::OLD_PASSWORD, $user->fresh()->password)
        );
    }

    public function test_reusing_current_password_is_rejected(): void
    {
        $user = $this->makeStaff('admin');

        $this->actingAs($user)
            ->post(route('profile.password.update'), $this->validPayload([
                'password' => self::OLD_PASSWORD,
                'password_confirmation' => self::OLD_PASSWORD,
            ]))
            ->assertSessionHasErrors(['password'], null, 'changePassword');

        $this->assertTrue(
            Hash::check(self::OLD_PASSWORD, $user->fresh()->password)
        );
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        $user = $this->makeStaff('coach');

        $this->actingAs($user)
            ->post(route('profile.password.update'), $this->validPayload([
                'password_confirmation' => 'Different-Pass-9',
            ]))
            ->assertSessionHasErrors(['password'], null, 'changePassword');

        $this->assertTrue(
            Hash::check(self::OLD_PASSWORD, $user->fresh()->password)
        );
    }

    public function test_unauthenticated_user_cannot_change_password(): void
    {
        $this->post(
            route('profile.password.update'),
            $this->validPayload()
        )->assertRedirect(route('login'));
    }

    public function test_successful_change_records_an_audit_event(): void
    {
        $user = $this->makeStaff('owner');

        $this->actingAs($user)
            ->post(route('profile.password.update'), $this->validPayload())
            ->assertRedirect(route('profile.show'));

        $log = AuditLog::where('action', 'PASSWORD_CHANGED')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringNotContainsString(
            self::NEW_PASSWORD,
            json_encode($log->toArray())
        );
        $this->assertStringNotContainsString(
            self::OLD_PASSWORD,
            json_encode($log->toArray())
        );
    }

    public function test_current_session_remains_authenticated_after_change(): void
    {
        $user = $this->makeStaff('owner');

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::OLD_PASSWORD,
        ]);

        $this->assertAuthenticatedAs($user);

        $this->post(
            route('profile.password.update'),
            $this->validPayload()
        )->assertRedirect(route('profile.show'));

        $this->app['auth']->forgetGuards();

        $this->get(route('profile.show'))->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_session_is_signed_out_after_password_is_changed_elsewhere(): void
    {
        $user = $this->makeStaff('admin');

        // Establish a real authenticated session and let the middleware
        // record the password fingerprint.
        $this->post('/login', [
            'email' => $user->email,
            'password' => self::OLD_PASSWORD,
        ]);

        $this->assertAuthenticatedAs($user);

        $this->app['auth']->forgetGuards();

        $this->get(route('profile.show'))->assertOk();

        // Simulate the same account changing its password from another session/device.
        $user->forceFill([
            'password' => Hash::make(self::NEW_PASSWORD),
        ])->save();

        // Force Laravel to resolve the authenticated user fresh from the session/database.
        $this->app['auth']->forgetGuards();

        $this->get(route('profile.show'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
