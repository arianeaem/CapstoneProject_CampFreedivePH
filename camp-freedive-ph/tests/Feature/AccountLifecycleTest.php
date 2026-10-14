<?php

namespace Tests\Feature;

use App\Mail\AccountRemovedMail;
use App\Mail\PasswordResetMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Account lifecycle (Owner, Admin, Coach): creation, activation, deactivation,
 * password resets, forced password change, sessions, role changes and removal.
 *
 * Every test asserts the SECURE expected behaviour; a failing test is a
 * demonstrated defect. Synthetic accounts only (example.test), in-memory database.
 */
class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $admin;
    protected User $coach;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $make = fn (string $role, string $email) => User::factory()->create([
            'role' => $role, 'email' => $email, 'status' => 'active', 'must_change_password' => false,
            'password' => Hash::make('Synthetic-Pass-1'),
        ]);
        $this->owner = $make('owner', 'lc.owner@example.test');
        $this->admin = $make('admin', 'lc.admin@example.test');
        $this->coach = $make('coach', 'lc.coach@example.test');
    }

    /** Log in through the real login form, so the session works like a browser's. */
    private function loginAs(User $user, string $password = 'Synthetic-Pass-1'): void
    {
        $this->post('/login', ['email' => $user->email, 'password' => $password]);
        $this->assertAuthenticatedAs($user);
    }

    /** Next request re-reads the user from the session and database, like a new browser request. */
    private function nextRequest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function userPayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Synthetic', 'last_name' => 'Person', 'no_middle_name' => 1,
            'email' => $user->email, 'phone' => '09170000000', 'role' => $user->role, 'status' => 'active',
        ], $overrides);
    }

    private function lastLog(string $action): ?AuditLog
    {
        return AuditLog::where('action', $action)->latest('id')->first();
    }

    // ================================================================ creation

    /** LC-001 */
    public function test_owner_creates_admin_and_coach_with_forced_password_change_and_audit(): void
    {
        foreach (['admin', 'coach'] as $role) {
            $this->actingAs($this->owner)->post('/owner/settings/users', $this->userPayload($this->owner, [
                'email' => "lc.new.{$role}@example.test", 'role' => $role,
            ]))->assertSessionHas('new_user_credentials');

            $created = User::where('email', "lc.new.{$role}@example.test")->firstOrFail();
            $this->assertSame($role, $created->role);
            $this->assertSame('active', $created->status);
            $this->assertTrue($created->must_change_password);
        }
        $this->assertSame(2, AuditLog::where('action', 'USER_CREATED')->count());
    }

    /** LC-002 */
    public function test_admin_can_create_coach_but_not_admin_or_owner(): void
    {
        $this->actingAs($this->admin)->post('/admin/settings/users', $this->userPayload($this->admin, [
            'email' => 'lc.admin.made.coach@example.test', 'role' => 'coach',
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'lc.admin.made.coach@example.test', 'role' => 'coach']);

        foreach (['admin', 'owner'] as $role) {
            $this->actingAs($this->admin)->post('/admin/settings/users', $this->userPayload($this->admin, [
                'email' => "lc.escalate.{$role}@example.test", 'role' => $role,
            ]))->assertSessionHasErrors('role');
            $this->assertDatabaseMissing('users', ['email' => "lc.escalate.{$role}@example.test"]);
        }
    }

    /** LC-003 */
    public function test_owner_cannot_create_another_owner_through_the_create_form(): void
    {
        $this->actingAs($this->owner)->post('/owner/settings/users', $this->userPayload($this->owner, [
            'email' => 'lc.second.owner@example.test', 'role' => 'owner',
        ]))->assertSessionHasErrors('role');
    }

    /** LC-004 */
    public function test_client_cannot_inject_status_or_skip_forced_password_change_on_create(): void
    {
        $this->actingAs($this->owner)->post('/owner/settings/users', $this->userPayload($this->owner, [
            'email' => 'lc.inject@example.test', 'role' => 'coach', 'must_change_password' => 0, 'status' => 'archived',
        ]));
        $created = User::where('email', 'lc.inject@example.test')->firstOrFail();
        $this->assertTrue($created->must_change_password);
        $this->assertSame('active', $created->status);
    }

    /** LC-005 (defect L-03: generated temporary password is predictable) */
    public function test_generated_temporary_password_is_not_guessable(): void
    {
        $response = $this->actingAs($this->owner)->post('/owner/settings/users', $this->userPayload($this->owner, [
            'email' => 'lc.temp@example.test', 'role' => 'coach',
        ]));
        $temp = $response->getSession()->get('new_user_credentials')['temp_password'];

        $this->assertDoesNotMatchRegularExpression('/^TempPass\d{4}!$/', $temp, 'Temp password comes from only 9,000 possibilities');
        $this->assertGreaterThanOrEqual(12, strlen($temp));
    }

    /** LC-006 */
    public function test_new_account_must_change_password_before_using_any_portal(): void
    {
        $this->owner->update(['must_change_password' => true]);
        $this->actingAs($this->owner)->get('/owner')->assertRedirect(route('password.force_change'));
        $this->actingAs($this->owner)->get('/owner/settings/users')->assertRedirect(route('password.force_change'));
    }

    // ================================================================ forced password change

    /** LC-010 */
    public function test_forced_change_requires_the_current_temporary_password(): void
    {
        $this->owner->update(['must_change_password' => true]);
        $this->actingAs($this->owner)->post('/force-password-change', [
            'current_password' => 'wrong-temp', 'password' => 'Brand-New-Pass-9', 'password_confirmation' => 'Brand-New-Pass-9',
        ])->assertSessionHasErrors('current_password');
        $this->assertTrue($this->owner->fresh()->must_change_password);
    }

    /** LC-011 */
    public function test_forced_change_rejects_reusing_the_temporary_password(): void
    {
        $this->owner->update(['must_change_password' => true]);
        $this->actingAs($this->owner)->post('/force-password-change', [
            'current_password' => 'Synthetic-Pass-1', 'password' => 'Synthetic-Pass-1', 'password_confirmation' => 'Synthetic-Pass-1',
        ])->assertSessionHasErrors('password');
    }

    /** LC-012 */
    public function test_forced_change_succeeds_clears_flag_and_is_audited(): void
    {
        $this->owner->update(['must_change_password' => true]);
        $this->actingAs($this->owner)->post('/force-password-change', [
            'current_password' => 'Synthetic-Pass-1', 'password' => 'Brand-New-Pass-9', 'password_confirmation' => 'Brand-New-Pass-9',
        ])->assertRedirect(route('owner.dashboard'));

        $this->assertFalse($this->owner->fresh()->must_change_password);
        $this->assertTrue(Hash::check('Brand-New-Pass-9', $this->owner->fresh()->password));
        $this->assertNotNull($this->lastLog('PASSWORD_CHANGED'));
    }

    // ================================================================ activation / deactivation / sessions

    /** LC-020 */
    public function test_deactivated_admin_loses_access_through_existing_session(): void
    {
        $this->loginAs($this->admin);
        $this->nextRequest()->get('/admin')->assertOk();

        $this->admin->update(['status' => 'inactive']); // deactivated by the owner elsewhere

        $this->nextRequest()->get('/admin/bookings')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotNull($this->lastLog('LOGIN_DEACTIVATED_BLOCKED'));
    }

    /** LC-021 */
    public function test_deactivated_coach_loses_access_through_existing_session(): void
    {
        $this->loginAs($this->coach);
        $this->coach->update(['status' => 'inactive']);

        $this->nextRequest()->get('/coach/my-schedule')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** LC-022 */
    public function test_removed_account_loses_access_through_existing_session(): void
    {
        $this->loginAs($this->coach);
        $this->coach->update(['status' => 'archived']);

        $this->nextRequest()->post('/coach/availability/toggle', ['date' => now()->addDays(10)->toDateString()])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** LC-023 */
    public function test_owner_toggle_deactivates_and_reactivates_and_both_are_audited(): void
    {
        $this->actingAs($this->owner)->patch("/owner/settings/users/{$this->admin->id}/toggle-status");
        $this->assertSame('inactive', $this->admin->fresh()->status);

        $this->nextRequest();
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'Synthetic-Pass-1'])
            ->assertSessionHas('error', 'This account has been deactivated. Please contact the camp owner.');
        $this->assertGuest();

        $this->actingAs($this->owner)->patch("/owner/settings/users/{$this->admin->id}/toggle-status");
        $this->assertSame('active', $this->admin->fresh()->status);
        $this->assertSame(2, AuditLog::where('action', 'USER_STATUS_TOGGLED')->count());

        $this->app['auth']->forgetGuards();
        $this->loginAs($this->admin);
    }

    /** LC-024 */
    public function test_admin_cannot_change_status_of_owner_or_admin_and_nobody_toggles_themselves(): void
    {
        $otherAdmin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'must_change_password' => false]);

        $this->actingAs($this->admin)->patch("/admin/settings/users/{$this->owner->id}/toggle-status")->assertForbidden();
        $this->actingAs($this->admin)->patch("/admin/settings/users/{$otherAdmin->id}/toggle-status")->assertForbidden();
        $this->actingAs($this->owner)->patch("/owner/settings/users/{$this->owner->id}/toggle-status")->assertSessionHas('error');

        $this->assertSame('active', $this->owner->fresh()->status);
        $this->assertSame('active', $otherAdmin->fresh()->status);
    }

    /** LC-025 */
    public function test_logout_invalidates_the_session(): void
    {
        $this->loginAs($this->owner);
        $this->nextRequest()->post('/logout')->assertRedirect(route('login'));
        $this->nextRequest()->get('/owner')->assertRedirect(route('login'));
    }

    // ================================================================ password resets

    /** LC-030 */
    public function test_reset_request_has_same_response_for_unknown_inactive_and_active_accounts(): void
    {
        $this->coach->update(['status' => 'inactive']);
        $messages = [];
        foreach (['nobody@example.test', $this->coach->email, $this->admin->email] as $email) {
            $messages[] = $this->post('/forgot-password', ['email' => $email])->getSession()->get('status');
        }
        $this->assertCount(1, array_unique($messages));
        Mail::assertSent(PasswordResetMail::class, 1); // only the active admin gets a link
    }

    /** LC-031 */
    public function test_reset_token_is_single_use_and_expires_after_60_minutes(): void
    {
        DB::table('password_reset_tokens')->insert(['email' => $this->admin->email, 'token' => Hash::make('synthetic-token'), 'created_at' => now()]);
        $payload = ['token' => 'synthetic-token', 'email' => $this->admin->email, 'password' => 'Reset-Pass-123', 'password_confirmation' => 'Reset-Pass-123'];

        $this->post('/reset-password', $payload)->assertRedirect(route('login'));
        $this->assertNotNull($this->lastLog('PASSWORD_RESET'));
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');

        DB::table('password_reset_tokens')->insert(['email' => $this->coach->email, 'token' => Hash::make('old-token'), 'created_at' => now()->subMinutes(61)]);
        $this->post('/reset-password', array_merge($payload, ['token' => 'old-token', 'email' => $this->coach->email]))->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('Synthetic-Pass-1', $this->coach->fresh()->password));
    }

    /** LC-032 (defect L-01: reset link written to the application log) */
    public function test_reset_link_is_not_written_to_application_logs(): void
    {
        Log::spy();
        $this->post('/forgot-password', ['email' => $this->admin->email]);

        Log::shouldNotHaveReceived('info', [Mockery::on(fn ($message) => is_string($message) && str_contains($message, 'reset-password/'))]);

        // The e-mail still carries the link, and the audit event is kept without it
        Mail::assertSent(PasswordResetMail::class, fn ($mail) => $mail->hasTo($this->admin->email));
        $audit = $this->lastLog('PASSWORD_RESET_LINK_SENT');
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('reset-password/', $audit->description);
    }

    /** LC-033 (defect L-02: other sessions survive a password change) */
    public function test_password_change_signs_out_other_existing_sessions(): void
    {
        $this->loginAs($this->admin);

        // Password reset completed from another device (e.g. after a suspected compromise)
        $this->admin->forceFill(['password' => Hash::make('Reset-From-Elsewhere-1')])->save();

        $this->nextRequest()->get('/admin')->assertRedirect(route('login'));
    }

    /** LC-034 (defect L-05: reset token issued while active still works after deactivation) */
    public function test_reset_token_cannot_be_used_after_account_is_deactivated(): void
    {
        DB::table('password_reset_tokens')->insert(['email' => $this->coach->email, 'token' => Hash::make('pre-deactivation'), 'created_at' => now()]);
        $this->coach->update(['status' => 'inactive']);

        $this->post('/reset-password', [
            'token' => 'pre-deactivation', 'email' => $this->coach->email, 'password' => 'Reset-Pass-123', 'password_confirmation' => 'Reset-Pass-123',
        ]);
        $this->assertTrue(Hash::check('Synthetic-Pass-1', $this->coach->fresh()->password));
    }

    /** LC-035 (after L-02: the old session is signed out, not just sent to the forced change) */
    public function test_admin_reset_of_coach_password_signs_out_old_session(): void
    {
        $this->loginAs($this->coach);

        // Admin assigns a temporary password (stored exactly like UserManagementController::update)
        $this->coach->update(['password' => Hash::make('Admin-Temp-Pass-1'), 'must_change_password' => true]);

        $this->nextRequest()->get('/coach/my-schedule')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** LC-037 (L-02: the session that changes its own password stays logged in) */
    public function test_forced_change_keeps_the_current_session_and_ends_others(): void
    {
        $this->owner->update(['must_change_password' => true]);
        $this->loginAs($this->owner);

        $this->nextRequest()->post('/force-password-change', [
            'current_password' => 'Synthetic-Pass-1', 'password' => 'Brand-New-Pass-9', 'password_confirmation' => 'Brand-New-Pass-9',
        ])->assertRedirect(route('owner.dashboard'));

        $this->nextRequest()->get('/owner')->assertOk();
        $this->assertAuthenticatedAs($this->owner);
    }

    /** LC-038 (L-02: "remember me" cookies stop working after a password change) */
    public function test_password_change_rotates_the_remember_token(): void
    {
        $before = $this->admin->fresh()->getRememberToken();
        $this->admin->update(['password' => Hash::make('Rotated-Pass-123')]);
        $this->assertNotSame($before, $this->admin->fresh()->getRememberToken());

        $unchanged = $this->admin->fresh()->getRememberToken();
        $this->admin->update(['phone' => '09179999999']);
        $this->assertSame($unchanged, $this->admin->fresh()->getRememberToken());
    }

    /** LC-039 (L-05: removed accounts too) */
    public function test_reset_token_cannot_be_used_after_account_is_removed(): void
    {
        DB::table('password_reset_tokens')->insert(['email' => $this->admin->email, 'token' => Hash::make('pre-removal'), 'created_at' => now()]);
        $this->admin->update(['status' => 'archived']);

        $this->post('/reset-password', [
            'token' => 'pre-removal', 'email' => $this->admin->email, 'password' => 'Reset-Pass-123', 'password_confirmation' => 'Reset-Pass-123',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('Synthetic-Pass-1', $this->admin->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->admin->email]);
    }

    /** LC-036 */
    public function test_admin_password_reset_is_audited_without_the_password(): void
    {
        $this->actingAs($this->admin)->put("/admin/settings/users/{$this->coach->id}", $this->userPayload($this->coach, [
            'new_password' => 'Admin-Temp-Pass-1',
        ]))->assertSessionHas('new_user_credentials');

        $this->assertTrue($this->coach->fresh()->must_change_password);
        $log = $this->lastLog('USER_UPDATED');
        $this->assertStringContainsString('Password reset by admin', $log->description);
        $this->assertStringNotContainsString('Admin-Temp-Pass-1', AuditLog::pluck('description')->implode(' '));
    }

    // ================================================================ role modification

    /** LC-040 */
    public function test_admin_cannot_elevate_coach_or_edit_admin_owner_or_self(): void
    {
        $this->actingAs($this->admin)->put("/admin/settings/users/{$this->coach->id}", $this->userPayload($this->coach, ['role' => 'admin']))
            ->assertSessionHasErrors('role');
        $this->actingAs($this->admin)->put("/admin/settings/users/{$this->admin->id}", $this->userPayload($this->admin, ['role' => 'owner']))
            ->assertForbidden();
        $this->actingAs($this->admin)->put("/admin/settings/users/{$this->owner->id}", $this->userPayload($this->owner, ['role' => 'coach']))
            ->assertForbidden();
        $this->actingAs($this->admin)->get("/admin/settings/users/{$this->owner->id}/edit")->assertForbidden();

        $this->assertSame('coach', $this->coach->fresh()->role);
        $this->assertSame('admin', $this->admin->fresh()->role);
        $this->assertSame('owner', $this->owner->fresh()->role);
    }

    /** LC-041 */
    public function test_role_change_takes_effect_on_existing_session_immediately(): void
    {
        $this->loginAs($this->admin);
        $this->admin->update(['role' => 'coach']); // owner demotes the admin

        $this->nextRequest()->get('/admin/bookings')->assertForbidden();
        $this->nextRequest()->get('/coach')->assertOk();
    }

    /** LC-042 */
    public function test_role_change_is_audited_with_old_and_new_role(): void
    {
        $this->actingAs($this->owner)->put("/owner/settings/users/{$this->coach->id}", $this->userPayload($this->coach, ['role' => 'admin']));
        $this->assertSame('admin', $this->coach->fresh()->role);
        $this->assertStringContainsString('Role: coach → admin', $this->lastLog('USER_UPDATED')->description);
    }

    /** LC-043 (defect L-04: audit entries do not record who acted by account ID) */
    public function test_admin_actions_record_the_acting_account_id(): void
    {
        $this->actingAs($this->owner)->put("/owner/settings/users/{$this->coach->id}", $this->userPayload($this->coach, ['role' => 'admin']));
        $log = $this->lastLog('USER_UPDATED');

        $this->assertSame($this->coach->id, $log->user_id, 'user_id holds the target account');
        $this->assertSame($this->owner->id, $log->actor_id ?? null, 'the acting account is only stored as a free-text name');
    }

    /** LC-044 (L-04: actor and target are kept apart; guests have no actor) */
    public function test_actor_id_is_self_for_login_and_null_for_guests(): void
    {
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);
        $this->assertNull($this->lastLog('LOGIN_FAILED')->actor_id);

        $this->app['auth']->forgetGuards();
        $this->loginAs($this->coach);
        $login = $this->lastLog('LOGIN_SUCCESS');
        $this->assertSame($this->coach->id, $login->user_id);
        $this->assertSame($this->coach->id, $login->actor_id);
        $this->assertSame($this->coach->id, $login->actor->id);
    }

    // ================================================================ R-13: last active owner

    /** LC-050 (R-13) */
    public function test_last_active_owner_cannot_demote_themselves(): void
    {
        $this->actingAs($this->owner)->put("/owner/settings/users/{$this->owner->id}", $this->userPayload($this->owner, ['role' => 'admin']));
        $this->assertSame('owner', $this->owner->fresh()->role);
    }

    /** LC-051 (R-13) */
    public function test_last_active_owner_cannot_deactivate_themselves_through_the_edit_form(): void
    {
        $this->actingAs($this->owner)->put("/owner/settings/users/{$this->owner->id}", $this->userPayload($this->owner, ['status' => 'inactive']));
        $this->assertSame('active', $this->owner->fresh()->status);
    }

    /** LC-052 (R-13) */
    public function test_inactive_second_owner_does_not_count_as_an_active_owner(): void
    {
        User::factory()->create(['role' => 'owner', 'status' => 'inactive', 'email' => 'lc.dormant.owner@example.test']);

        $this->actingAs($this->owner)->put("/owner/settings/users/{$this->owner->id}", $this->userPayload($this->owner, ['role' => 'coach']));
        $this->assertSame('owner', $this->owner->fresh()->role);
    }

    /** LC-053 (R-13) */
    public function test_last_active_owner_cannot_be_removed(): void
    {
        $this->actingAs($this->owner)->delete("/owner/settings/users/{$this->owner->id}")->assertSessionHas('error');
        $this->actingAs($this->admin)->delete("/admin/settings/users/{$this->owner->id}")->assertForbidden();
        $this->assertSame('active', $this->owner->fresh()->status);
    }

    /** LC-054 (R-13 positive) */
    public function test_with_two_active_owners_one_may_demote_the_other(): void
    {
        $second = User::factory()->create(['role' => 'owner', 'status' => 'active', 'must_change_password' => false, 'email' => 'lc.owner2@example.test']);

        $this->actingAs($this->owner)->put("/owner/settings/users/{$second->id}", $this->userPayload($second, ['role' => 'admin']))
            ->assertSessionHasNoErrors();
        $this->assertSame('admin', $second->fresh()->role);
    }

    /** LC-055 (R-13: the guard re-reads owners at the time of the change) */
    public function test_owner_cannot_demote_self_after_the_other_owner_was_demoted(): void
    {
        $second = User::factory()->create(['role' => 'owner', 'status' => 'active', 'must_change_password' => false, 'email' => 'lc.owner3@example.test']);

        $this->actingAs($this->owner)->put("/owner/settings/users/{$second->id}", $this->userPayload($second, ['role' => 'admin']));
        $this->actingAs($this->owner)->put("/owner/settings/users/{$this->owner->id}", $this->userPayload($this->owner, ['role' => 'admin']))
            ->assertSessionHas('error', \App\Services\UserAccountService::LAST_OWNER_MESSAGE);

        $this->assertSame('owner', $this->owner->fresh()->role);
        $this->assertSame(1, User::where('role', 'owner')->where('status', 'active')->count());
    }

    /** LC-056 (R-13: toggle and remove use the same guard; another owner can still be removed) */
    public function test_one_of_two_owners_can_deactivate_or_remove_the_other(): void
    {
        $second = User::factory()->create(['role' => 'owner', 'status' => 'active', 'must_change_password' => false, 'email' => 'lc.owner4@example.test']);
        $third = User::factory()->create(['role' => 'owner', 'status' => 'active', 'must_change_password' => false, 'email' => 'lc.owner5@example.test']);

        $this->actingAs($this->owner)->patch("/owner/settings/users/{$second->id}/toggle-status")->assertSessionHas('success');
        $this->actingAs($this->owner)->delete("/owner/settings/users/{$third->id}")->assertSessionHas('success');

        $this->assertSame('inactive', $second->fresh()->status);
        $this->assertSame('archived', $third->fresh()->status);
        $this->assertSame('active', $this->owner->fresh()->status);
    }

    /** LC-057 (R-13: the guard itself refuses a change that would leave no active owner) */
    public function test_owner_guard_refuses_and_rolls_back_when_no_active_owner_would_remain(): void
    {
        $accounts = app(\App\Services\UserAccountService::class);
        $ran = false;

        $allowed = $accounts->withOwnerGuard($this->owner, false, function () use (&$ran) { $ran = true; });

        $this->assertFalse($allowed);
        $this->assertFalse($ran, 'the change must not run');
        $this->assertTrue($accounts->withOwnerGuard($this->coach, false, fn () => null), 'non-owners are not affected');
    }

    // ================================================================ additional findings (S-1 .. S-4)

    /** LC-080 (S-1) */
    public function test_demo_seeders_refuse_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        try {
            foreach ([\Database\Seeders\DatabaseSeeder::class, \Database\Seeders\DemoSeeder::class] as $seeder) {
                try {
                    $this->app->make($seeder)->run(); // directly, as `db:seed --force` would
                    $this->fail("{$seeder} ran in production");
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('cannot run in production', $e->getMessage());
                }
            }
        } finally {
            $this->app['env'] = 'testing';
        }

        // Nothing was cleared or created
        $this->assertSame(3, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'owner@campfreedive.ph']);
    }

    /** LC-081 (S-2) */
    public function test_coach_with_upcoming_students_cannot_be_moved_to_another_role(): void
    {
        $batch = \App\Models\Batch::create([
            'batch_code' => 'LC-UP-01', 'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(11)->toDateString(), 'status' => 'confirmed',
        ]);
        $booking = \App\Models\Booking::create([
            'batch_id' => $batch->id, 'booking_number' => 'CFP-LC-' . uniqid(), 'pin' => '1234', 'class_type' => 'discovery', 'status' => 'confirmed',
            'total_amount' => 4250, 'contact_name' => 'Synthetic Lead', 'contact_email' => 'lead@example.test', 'contact_phone' => '09170000000',
            'start_date' => $batch->start_date, 'end_date' => $batch->end_date,
        ]);
        $participant = \App\Models\BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Synthetic Student', 'age' => 20, 'price_per_person' => 4250]);
        \App\Models\ParticipantAssignment::create([
            'participant_id' => $participant->id, 'booking_id' => $booking->id, 'coach_id' => $this->coach->id, 'batch_id' => $batch->id,
            'dive_date' => $batch->start_date, 'assigned_by' => $this->owner->id, 'assigned_at' => now(), 'status' => 'assigned',
        ]);

        $this->actingAs($this->owner)->put("/owner/settings/users/{$this->coach->id}", $this->userPayload($this->coach, ['role' => 'admin']))
            ->assertSessionHas('error');
        $this->assertSame('coach', $this->coach->fresh()->role);
    }

    /** LC-082 (S-3) */
    public function test_restoring_through_the_edit_form_is_logged_as_a_restore(): void
    {
        $this->coach->update(['status' => 'archived']);

        $this->actingAs($this->owner)->put("/owner/settings/users/{$this->coach->id}", $this->userPayload($this->coach, ['status' => 'active']));

        $this->assertSame('active', $this->coach->fresh()->status);
        $restore = $this->lastLog('USER_RESTORED');
        $this->assertNotNull($restore);
        $this->assertSame($this->coach->id, $restore->user_id);
        $this->assertSame($this->owner->id, $restore->actor_id);
    }

    /** LC-083 (S-4: one password rule for create, admin reset, forced change and forgot-password) */
    public function test_weak_passwords_are_rejected_everywhere(): void
    {
        $weak = ['Short1a', 'alllowercase123', 'NoNumbersHereAtAll'];
        // This test sends more reset/forced-change requests than the 5-per-15-minutes limit allows
        config(['rate_limits.password_reset.max_attempts' => 100]);

        foreach ($weak as $password) {
            $this->actingAs($this->owner)->post('/owner/settings/users', $this->userPayload($this->owner, [
                'email' => 'lc.weak.' . md5($password) . '@example.test', 'role' => 'coach', 'temp_password' => $password,
            ]))->assertSessionHasErrors('temp_password');

            $this->actingAs($this->owner)->put("/owner/settings/users/{$this->coach->id}", $this->userPayload($this->coach, ['new_password' => $password]))
                ->assertSessionHasErrors('new_password');

            $this->post('/reset-password', ['token' => 'x', 'email' => $this->admin->email, 'password' => $password, 'password_confirmation' => $password])
                ->assertSessionHasErrors('password');
        }

        $this->admin->update(['must_change_password' => true]);
        foreach ($weak as $password) {
            $this->actingAs($this->admin)->post('/force-password-change', [
                'current_password' => 'Synthetic-Pass-1', 'password' => $password, 'password_confirmation' => $password,
            ])->assertSessionHasErrors('password');
        }

        $this->assertTrue(Hash::check('Synthetic-Pass-1', $this->coach->fresh()->password));
        $this->assertTrue(Hash::check('Synthetic-Pass-1', $this->admin->fresh()->password));
    }

    // ================================================================ removal

    /** LC-060 */
    public function test_owner_removes_admin_archived_notified_audited_and_login_blocked(): void
    {
        $this->actingAs($this->owner)->delete("/owner/settings/users/{$this->admin->id}")->assertSessionHas('success');

        $this->assertSame('archived', $this->admin->fresh()->status);
        $this->assertDatabaseHas('users', ['id' => $this->admin->id]); // history kept
        Mail::assertSent(AccountRemovedMail::class, fn ($m) => $m->hasTo($this->admin->email));
        $this->assertStringContainsString($this->admin->email, $this->lastLog('USER_REMOVED')->description);

        $this->nextRequest();
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'Synthetic-Pass-1']);
        $this->assertGuest();
    }

    /** LC-061 */
    public function test_admin_can_remove_coach_but_not_admin_or_owner(): void
    {
        $this->actingAs($this->admin)->delete("/admin/settings/users/{$this->coach->id}")->assertSessionHas('success');
        $this->assertSame('archived', $this->coach->fresh()->status);

        $otherAdmin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'must_change_password' => false]);
        $this->actingAs($this->admin)->delete("/admin/settings/users/{$otherAdmin->id}")->assertForbidden();
        $this->assertSame('active', $otherAdmin->fresh()->status);
    }

    /** LC-062 */
    public function test_removed_account_can_be_restored_and_restore_is_audited(): void
    {
        $this->actingAs($this->owner)->delete("/owner/settings/users/{$this->coach->id}");
        $this->actingAs($this->owner)->patch("/owner/settings/users/{$this->coach->id}/toggle-status");

        $this->assertSame('active', $this->coach->fresh()->status);
        $this->assertNotNull($this->lastLog('USER_RESTORED'));
    }

    // ================================================================ audit content

    /** LC-070 */
    public function test_account_creation_audit_names_actor_and_target_without_the_password(): void
    {
        $response = $this->actingAs($this->owner)->post('/owner/settings/users', $this->userPayload($this->owner, [
            'email' => 'lc.audited@example.test', 'role' => 'coach', 'temp_password' => 'Chosen-Temp-Pass-1',
        ]));
        $response->assertSessionHas('new_user_credentials');

        $log = $this->lastLog('USER_CREATED');
        $this->assertStringContainsString('lc.audited@example.test', $log->description);
        $this->assertSame($this->owner->name, $log->actor_name);
        $this->assertStringNotContainsString('Chosen-Temp-Pass-1', AuditLog::pluck('description')->implode(' '));
    }
}
