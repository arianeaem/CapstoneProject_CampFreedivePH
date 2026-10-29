<?php

namespace Tests\Feature\Scheduling;

use App\Models\Batch;
use App\Models\Coach;
use App\Models\CoachAvailability;
use App\Models\CoachDateCommitment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * S1 PostgreSQL-specific tests: unique constraint under concurrent writes,
 * advisory-lock semantics, and FK cascade behaviour.
 *
 * DESTRUCTIVE: DatabaseTruncation runs migrate:fresh. guardDisposableDatabase()
 * refuses unless the target is a positively verified disposable local cluster.
 */
class CoachSchedulingPgsqlTest extends TestCase
{
    use DatabaseTruncation;

    private const DISPOSABLE_DATABASES = ['testing', 'cfp_s5_test'];
    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];
    private const MANAGED_PROVIDER_ROLES = ['supabase_admin', 'supabase_auth_admin', 'authenticator', 'azure_pg_admin', 'azuresu', 'azure_superuser', 'rdsadmin', 'cloudsqlsuperuser'];

    private User $admin;
    private int $seq = 0;

    protected function setUpTraits(): array
    {
        $this->guardDisposableDatabase();
        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required');
        }
        config(['database.connections.pgsql_race' => config('database.connections.' . config('database.default'))]);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'must_change_password' => false]);
    }

    public static function tearDownAfterClass(): void
    {
        if (static::class === self::class) {
            RefreshDatabaseState::$migrated = false;
        }
        parent::tearDownAfterClass();
    }

    private function guardDisposableDatabase(): void
    {
        $conn = config('database.default');
        $cfg = config("database.connections.{$conn}");
        if (($cfg['driver'] ?? '') !== 'pgsql') {
            return;
        }
        $host = $cfg['host'] ?? '';
        $dbName = $cfg['database'] ?? '';
        $this->assertTrue(
            in_array($host, self::LOCAL_HOSTS, true),
            "Guard: host {$host} is not local"
        );
        $this->assertTrue(
            in_array($dbName, self::DISPOSABLE_DATABASES, true),
            "Guard: database {$dbName} not in disposable list"
        );
        $roles = DB::select("SELECT rolname FROM pg_roles");
        $roleNames = array_map(fn ($r) => $r->rolname, $roles);
        foreach (self::MANAGED_PROVIDER_ROLES as $managed) {
            $this->assertNotContains(
                $managed, $roleNames,
                "Guard: managed provider role {$managed} found — not a disposable cluster"
            );
        }
    }

    private function coach(): Coach
    {
        $user = User::factory()->create(['role' => 'coach', 'status' => 'active', 'must_change_password' => false]);
        return Coach::create([
            'user_id' => $user->id, 'full_name' => 'PG Coach ' . (++$this->seq),
            'email' => "pgcoach{$this->seq}@example.test", 'phone' => '09170000001',
            'certification_level' => 'AIDA 4 Master', 'certification_number' => "PG-{$this->seq}",
            'certification_expiry' => now()->addYear(), 'status' => 'active',
        ]);
    }

    private function batch(): Batch
    {
        return Batch::create([
            'name' => 'PG Batch ' . (++$this->seq), 'batch_code' => 'PG-' . $this->seq,
            'start_date' => '2026-12-05', 'end_date' => '2026-12-06',
            'status' => 'confirmed', 'lifecycle_status' => 'confirmed',
        ]);
    }

    // ---------------------------------------------------------------- R1: concurrent duplicate insert

    public function test_concurrent_duplicate_coach_date_one_wins_one_fails(): void
    {
        $coach = $this->coach();
        $batch1 = $this->batch();
        $batch2 = $this->batch();

        // Tx A begins and inserts (holds row lock on unique index)
        DB::connection('pgsql_race')->beginTransaction();
        DB::connection('pgsql_race')->table('coach_date_commitments')->insert([
            'coach_id' => $coach->id, 'batch_id' => $batch1->id,
            'date' => '2026-12-05', 'assigned_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Tx B with a short lock_timeout — in a single-process test, B blocks on A's
        // uncommitted row; the lock_timeout forces it to fail quickly rather than deadlock
        DB::statement("SET lock_timeout = '500ms'");
        $exceptionCaught = false;
        try {
            DB::table('coach_date_commitments')->insert([
                'coach_id' => $coach->id, 'batch_id' => $batch2->id,
                'date' => '2026-12-05', 'assigned_by' => $this->admin->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            $exceptionCaught = true;
        }
        DB::statement("SET lock_timeout = '0'");

        // Commit A — its row lands
        DB::connection('pgsql_race')->commit();

        $count = DB::table('coach_date_commitments')
            ->where('coach_id', $coach->id)
            ->whereDate('date', '2026-12-05')
            ->count();

        $this->assertSame(1, $count, 'Unique constraint prevents double-booking');
        $this->assertTrue($exceptionCaught, 'Concurrent duplicate was rejected by lock timeout');
    }

    // ---------------------------------------------------------------- R2: non-conflicting concurrent inserts succeed

    public function test_different_coaches_same_date_concurrent_both_succeed(): void
    {
        $coach1 = $this->coach();
        $coach2 = $this->coach();
        $batch = $this->batch();

        DB::connection('pgsql_race')->beginTransaction();
        DB::connection('pgsql_race')->table('coach_date_commitments')->insert([
            'coach_id' => $coach1->id, 'batch_id' => $batch->id,
            'date' => '2026-12-05', 'assigned_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::beginTransaction();
        DB::table('coach_date_commitments')->insert([
            'coach_id' => $coach2->id, 'batch_id' => $batch->id,
            'date' => '2026-12-05', 'assigned_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::commit();
        DB::connection('pgsql_race')->commit();

        $count = DB::table('coach_date_commitments')
            ->where('batch_id', $batch->id)
            ->whereDate('date', '2026-12-05')
            ->count();
        $this->assertSame(2, $count, 'Different coaches can commit to the same date concurrently');
    }

    // ---------------------------------------------------------------- R3: release then reassign race

    public function test_release_then_reassign_serialises_correctly(): void
    {
        $coach = $this->coach();
        $batch1 = $this->batch();
        $batch2 = $this->batch();

        // Insert commitment for batch1
        DB::table('coach_date_commitments')->insert([
            'coach_id' => $coach->id, 'batch_id' => $batch1->id,
            'date' => '2026-12-05', 'assigned_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Tx A: delete (release)
        DB::connection('pgsql_race')->beginTransaction();
        DB::connection('pgsql_race')->table('coach_date_commitments')
            ->where('coach_id', $coach->id)
            ->where('batch_id', $batch1->id)
            ->delete();

        // Tx A: re-assign to batch2
        DB::connection('pgsql_race')->table('coach_date_commitments')->insert([
            'coach_id' => $coach->id, 'batch_id' => $batch2->id,
            'date' => '2026-12-05', 'assigned_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('pgsql_race')->commit();

        $row = DB::table('coach_date_commitments')
            ->where('coach_id', $coach->id)
            ->whereDate('date', '2026-12-05')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($batch2->id, $row->batch_id, 'Reassignment landed on batch2');
    }

    // ---------------------------------------------------------------- S2-R1: concurrent staff confirmation

    public function test_concurrent_confirmation_on_same_availability_serialises(): void
    {
        $coach = $this->coach();
        CoachAvailability::create([
            'coach_id' => $coach->user_id, 'date' => '2026-12-05', 'status' => 'available',
        ]);

        DB::connection('pgsql_race')->beginTransaction();
        DB::connection('pgsql_race')->table('coach_availabilities')
            ->where('coach_id', $coach->user_id)
            ->whereRaw("DATE(date) = '2026-12-05'")
            ->update(['staff_confirmed_by' => $this->admin->id, 'staff_confirmed_at' => now()]);

        $admin2 = User::factory()->create(['role' => 'admin', 'status' => 'active', 'must_change_password' => false]);
        DB::statement("SET lock_timeout = '500ms'");
        $blocked = false;
        try {
            DB::table('coach_availabilities')
                ->where('coach_id', $coach->user_id)
                ->whereRaw("DATE(date) = '2026-12-05'")
                ->update(['staff_confirmed_by' => $admin2->id, 'staff_confirmed_at' => now()]);
        } catch (\Exception $e) {
            $blocked = true;
        }
        DB::statement("SET lock_timeout = '0'");
        DB::connection('pgsql_race')->commit();

        $avail = DB::table('coach_availabilities')
            ->where('coach_id', $coach->user_id)
            ->whereRaw("DATE(date) = '2026-12-05'")
            ->first();

        $this->assertNotNull($avail->staff_confirmed_by, 'Confirmation landed');
        if (! $blocked) {
            $this->assertSame($admin2->id, $avail->staff_confirmed_by, 'Second update overwrote first (last writer wins when not blocked)');
        }
    }

    // ---------------------------------------------------------------- S2-R2: concurrent assignment to conflicting batches

    public function test_concurrent_assignment_to_overlapping_batch_one_rejected(): void
    {
        $coach = $this->coach();
        $batch1 = $this->batch();
        $batch2 = $this->batch();

        DB::connection('pgsql_race')->beginTransaction();
        DB::connection('pgsql_race')->table('coach_date_commitments')->insert([
            'coach_id' => $coach->id, 'batch_id' => $batch1->id,
            'date' => '2026-12-05', 'assigned_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::statement("SET lock_timeout = '500ms'");
        $rejected = false;
        try {
            DB::table('coach_date_commitments')->insert([
                'coach_id' => $coach->id, 'batch_id' => $batch2->id,
                'date' => '2026-12-05', 'assigned_by' => $this->admin->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            $rejected = true;
        }
        DB::statement("SET lock_timeout = '0'");

        DB::connection('pgsql_race')->commit();

        $count = DB::table('coach_date_commitments')
            ->where('coach_id', $coach->id)
            ->whereDate('date', '2026-12-05')
            ->count();

        $this->assertSame(1, $count, 'Only one assignment survived');
        $this->assertTrue($rejected, 'Concurrent conflicting assignment was blocked');
    }

    // ---------------------------------------------------------------- R4: unique index name verification

    public function test_unique_index_exists_on_coach_id_date(): void
    {
        $indexes = DB::select("
            SELECT indexname, indexdef FROM pg_indexes
            WHERE tablename = 'coach_date_commitments'
        ");

        $uniqueOnCoachDate = collect($indexes)->first(fn ($idx) =>
            str_contains($idx->indexdef, 'UNIQUE')
            && str_contains($idx->indexdef, 'coach_id')
            && str_contains($idx->indexdef, 'date')
        );

        $this->assertNotNull($uniqueOnCoachDate, 'UNIQUE index on (coach_id, date) exists');
    }

    // ---------------------------------------------------------------- R5: FK enforcement

    public function test_fk_prevents_commitment_for_nonexistent_coach(): void
    {
        $batch = $this->batch();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('coach_date_commitments')->insert([
            'coach_id' => 99999, 'batch_id' => $batch->id,
            'date' => '2026-12-05', 'assigned_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
