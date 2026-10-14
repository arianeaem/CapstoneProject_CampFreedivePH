<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Deploy safety: a migration that has not run (or failed) must not lock anyone out, must be
 * visible on /up, and must roll back and re-apply cleanly. Runs on SQLite and PostgreSQL.
 */
class DeploymentSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR_MIGRATION = 'database/migrations/2026_10_14_000001_add_actor_id_to_audit_logs.php';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        AuditLogger::flushSchemaCache();

        $this->owner = User::factory()->create([
            'role' => 'owner', 'status' => 'active', 'must_change_password' => false,
            'email' => 'ds.owner@example.test', 'password' => Hash::make('Synthetic-Pass-1'),
        ]);
    }

    protected function tearDown(): void
    {
        AuditLogger::flushSchemaCache();
        parent::tearDown();
    }

    private function rollBackActorMigration(): void
    {
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::ACTOR_MIGRATION, '--force' => true]));
        AuditLogger::flushSchemaCache();
        $this->assertFalse(Schema::hasColumn('audit_logs', 'actor_id'));
    }

    /** DS-01 */
    public function test_login_works_when_the_actor_id_migration_has_not_run(): void
    {
        $this->rollBackActorMigration();

        $this->post('/login', ['email' => $this->owner->email, 'password' => 'Synthetic-Pass-1'])->assertRedirect(route('owner.dashboard'));
        $this->assertAuthenticatedAs($this->owner);
        $this->get('/owner')->assertOk();

        $entry = AuditLog::where('action', 'LOGIN_SUCCESS')->latest('id')->first();
        $this->assertNotNull($entry, 'the login is still audited, just without actor_id');
        $this->assertSame($this->owner->id, $entry->user_id);
    }

    /** DS-02 */
    public function test_failed_login_still_answers_normally_when_the_migration_has_not_run(): void
    {
        $this->rollBackActorMigration();

        $this->post('/login', ['email' => $this->owner->email, 'password' => 'Wrong-Synthetic-1'])
            ->assertRedirect()
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNotNull(AuditLog::where('action', 'LOGIN_FAILED')->first());
    }

    /** DS-03 */
    public function test_login_works_even_if_the_audit_table_cannot_be_written(): void
    {
        Log::spy();
        Schema::rename('audit_logs', 'audit_logs_unavailable');
        try {
            $this->post('/login', ['email' => $this->owner->email, 'password' => 'Synthetic-Pass-1'])->assertRedirect(route('owner.dashboard'));
            $this->assertAuthenticatedAs($this->owner);
        } finally {
            Schema::rename('audit_logs_unavailable', 'audit_logs');
        }

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'Audit log entry could not be saved.'
            && $context['action'] === 'LOGIN_SUCCESS'
            && !str_contains(json_encode($context), 'SQLSTATE'));
    }

    /** DS-04 (PostgreSQL: a failed statement would otherwise abort the surrounding transaction) */
    public function test_a_failed_audit_write_does_not_break_the_surrounding_transaction(): void
    {
        Log::spy();

        DB::transaction(function () {
            Schema::rename('audit_logs', 'audit_logs_unavailable');
            AuditLogger::log('SYNTHETIC_EVENT', 'Inside a transaction.', $this->owner);
            Schema::rename('audit_logs_unavailable', 'audit_logs');

            // The transaction is still usable after the failed audit insert
            $this->owner->update(['phone' => '09170000003']);
        });

        $this->assertSame('09170000003', $this->owner->fresh()->phone);
    }

    /** DS-05 */
    public function test_health_check_reports_pending_migrations_and_recovers_after_migrating(): void
    {
        config(['app.debug' => false]); // as in production (APP_DEBUG=false): no exception page
        $this->get('/up')->assertOk();

        $this->rollBackActorMigration();
        $this->get('/up')->assertStatus(500)->assertDontSee('migration');
        $this->getJson('/up')->assertStatus(500)->assertExactJson(['status' => 'down']);

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->get('/up')->assertOk();
    }

    /** DS-06 (rollback evidence: the migration goes down and up cleanly; logging adapts) */
    public function test_actor_id_migration_rolls_back_and_reapplies_cleanly(): void
    {
        $this->actingAs($this->owner);

        $this->rollBackActorMigration();
        AuditLogger::log('SYNTHETIC_DOWN', 'Logged while the column is missing.', $this->owner);
        $this->assertNotNull(AuditLog::where('action', 'SYNTHETIC_DOWN')->first());

        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::ACTOR_MIGRATION, '--force' => true]));
        AuditLogger::flushSchemaCache();
        $this->assertTrue(Schema::hasColumn('audit_logs', 'actor_id'));

        AuditLogger::log('SYNTHETIC_UP', 'Logged after re-applying.', $this->owner);
        $this->assertSame($this->owner->id, AuditLog::where('action', 'SYNTHETIC_UP')->first()->actor_id);
        $this->assertNull(AuditLog::where('action', 'SYNTHETIC_DOWN')->first()->actor_id, 'older rows are kept, actor unknown');
    }
}
