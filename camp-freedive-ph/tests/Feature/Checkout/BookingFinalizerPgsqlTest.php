<?php

namespace Tests\Feature\Checkout;

use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Models\SystemSetting;
use App\Services\Checkout\BookingFinalizer;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\FinalizationResult;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\TestCase;

/**
 * Batch 2B PostgreSQL-only checks: real row locks, advisory locks, savepoints and cross-connection visibility.
 * SQLite cannot show these, so the class skips unless the test database is PostgreSQL (CI uses PostgreSQL 17).
 *
 * DESTRUCTIVE SETUP: DatabaseTruncation runs migrate:fresh on first use and truncates every table afterwards.
 * guardDisposableDatabase() runs before it (setUpTraits) and refuses unless ALL of these hold:
 *   - driver pgsql, no DB_URL, configured host is loopback (127.0.0.1 / localhost / ::1);
 *   - running in GitHub Actions (its throwaway postgres service) or CFP_DISPOSABLE_PGSQL=1 for a local throwaway container;
 *   - configured AND actually connected database name is in DISPOSABLE_DATABASES;
 *   - the server has none of the roles managed providers create (Supabase, Azure, RDS, Cloud SQL).
 * Uncertain identity fails the test before any destructive step. Not pgsql: skipped before any setup.
 *
 * Concurrency limits: each test uses a second PostgreSQL connection (a separate server session) from the same
 * PHP process. That shows a lock BLOCKS (until lock_timeout); it does not establish genuine two-process
 * interleaving. A multi-process race test is still to be written (Batch 2B.2 or later).
 */
class BookingFinalizerPgsqlTest extends TestCase
{
    use DatabaseTruncation;
    use FakesPayMongo;

    private const DATE = '2026-12-19';
    private const DISPOSABLE_DATABASES = ['testing'];
    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];
    private const MANAGED_PROVIDER_ROLES = ['supabase_admin', 'supabase_auth_admin', 'authenticator', 'azure_pg_admin', 'azuresu', 'azure_superuser', 'rdsadmin', 'cloudsqlsuperuser'];

    private int $seq = 0;

    /** Runs inside parent::setUp(), after the app boots and before DatabaseTruncation touches the database. */
    protected function setUpTraits()
    {
        $this->guardDisposableDatabase();

        return parent::setUpTraits();
    }

    private function guardDisposableDatabase(): void
    {
        $name = config('database.default');
        $config = (array) config("database.connections.{$name}");

        if (($config['driver'] ?? null) !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only: row/advisory locks and savepoints cannot be exercised on ' . ($config['driver'] ?? 'unknown') . '. Runs in CI (PostgreSQL 17).');
        }

        $refuse = fn (string $why) => $this->fail("Refusing destructive PostgreSQL test setup: {$why}");

        if (! empty($config['url'])) {
            $refuse('DB_URL is set; only explicit local host settings are accepted.');
        }
        $host = strtolower(trim((string) ($config['host'] ?? '')));
        if (! in_array($host, self::LOCAL_HOSTS, true)) {
            $refuse("host '{$host}' is not a local or CI service host.");
        }
        if (getenv('GITHUB_ACTIONS') !== 'true' && getenv('CFP_DISPOSABLE_PGSQL') !== '1') {
            $refuse('not in GitHub Actions, and CFP_DISPOSABLE_PGSQL=1 is not set for a local throwaway container.');
        }
        if (! in_array($config['database'] ?? null, self::DISPOSABLE_DATABASES, true)) {
            $refuse("configured database '" . ($config['database'] ?? '') . "' is not an approved disposable test database.");
        }

        try {
            $connection = DB::connection($name);
            $actual = $connection->selectOne('select current_database() as name')->name;
            $placeholders = implode(',', array_fill(0, count(self::MANAGED_PROVIDER_ROLES), '?'));
            $managed = (int) $connection->selectOne("select count(*) as n from pg_roles where rolname in ({$placeholders})", self::MANAGED_PROVIDER_ROLES)->n;
        } catch (\Throwable $e) {
            $refuse('the connected database identity could not be confirmed (' . class_basename($e) . ').');
        }

        if (! in_array($actual, self::DISPOSABLE_DATABASES, true)) {
            $refuse("connected database '{$actual}' is not an approved disposable test database.");
        }
        if ($managed > 0) {
            $refuse('the server has managed-provider roles (Supabase/Azure/cloud); never a disposable test server.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFakePayMongo();
        Mail::fake();
        Queue::fake();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00', 'Asia/Manila'));
        config(['checkout.finalization_lock_wait_seconds' => 0]);
        config(['database.connections.pgsql_second' => config('database.connections.' . config('database.default'))]);
        $this->mock(WeatherForecastService::class, fn ($mock) => $mock->shouldReceive('assessBatch')->andReturn([]));

        SystemSetting::create(['group' => 'camp_operations', 'key' => 'camp_operations.max_batch_capacity', 'value' => '6', 'type' => 'integer']);
        // Blocked capacity transactions fail after BookingFinalizer::DB_LOCK_TIMEOUT (SET LOCAL inside each transaction)
    }

    protected function tearDown(): void
    {
        if (DB::getConnections()['pgsql_second'] ?? null) {
            DB::connection('pgsql_second')->rollBack(0);
            DB::purge('pgsql_second');
        }
        Str::createRandomStringsNormally();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(int $pax, string $start): array
    {
        $end = Carbon::parse($start)->addDay()->toDateString();
        $participants = array_map(fn ($last) => ['name' => "Synthetic {$last}", 'birthdate' => null, 'gender' => 'prefer_not_to_say', 'age' => 30,
            'health_condition' => null, 'swimmer_status' => 'swimmer', 'price_per_person' => 4250.0], array_slice(['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot'], 0, $pax));

        return [
            'booking' => ['class_type' => 'discovery', 'is_certified_diver' => false, 'start_date' => $start, 'end_date' => $end, 'pickup_option' => 'own', 'pickup_location' => null, 'boat_dive' => false],
            'contact' => ['name' => 'Pat Fixture', 'email' => 'pat.fixture@example.test', 'phone' => '09170000003', 'facebook' => null],
            'guardian' => [],
            'participants' => $participants,
            'pricing' => ['pricing_version' => 1, 'subtotal' => 4250.0 * $pax, 'adjustments' => [],
                'fees' => ['lgu' => 300.0 * $pax, 'environmental' => 50.0 * $pax, 'carpool' => 0.0, 'boat_dive' => 0.0],
                'total_amount' => 4600 * $pax, 'downpayment_amount' => 2000 * $pax],
        ];
    }

    private function intent(int $pax = 2, array $overrides = [], string $start = self::DATE): CheckoutIntent
    {
        $n = ++$this->seq;

        return CheckoutIntent::create(array_merge([
            'reference' => sprintf('CHK-PGSQL%07d', $n), 'status' => CheckoutIntent::STATUS_PENDING,
            'start_date' => $start, 'end_date' => Carbon::parse($start)->addDay()->toDateString(),
            'pax_count' => $pax, 'class_type' => 'discovery', 'pickup_option' => 'own', 'currency' => 'PHP',
            'total_amount' => 4600 * $pax, 'downpayment_amount' => 2000 * $pax, 'paymongo_checkout_session_id' => "cs_test_pg_{$n}",
            'hold_expires_at' => now()->addMinutes(15),
            'payload' => $this->payload($pax, $start),
        ], $overrides));
    }

    private function finalize(CheckoutIntent $intent): FinalizationResult
    {
        return app(BookingFinalizer::class)->finalize($intent->fresh(), $this->verifiedResultFor($intent), 'pgsql-test');
    }

    private function second()
    {
        return DB::connection('pgsql_second');
    }

    private function holdAdvisoryLockOnSecondConnection(): void
    {
        $this->second()->beginTransaction();
        $this->second()->select('select pg_advisory_xact_lock(?)', [crc32('checkout_capacity:' . self::DATE)]);
    }

    public function test_finalizer_waits_on_the_date_advisory_lock_held_by_another_connection(): void
    {
        $intent = $this->intent();
        $this->holdAdvisoryLockOnSecondConnection();

        $blocked = $this->finalize($intent);

        $this->assertSame(FinalizationResult::RETRYABLE, $blocked->outcome);
        $this->assertSame('capacity_lock_unavailable', $blocked->reason);
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
        $this->assertSame(0, Booking::count());

        $this->second()->rollBack();
        $this->assertSame(FinalizationResult::FINALIZED, $this->finalize($intent)->outcome);
    }

    public function test_checkout_start_waits_on_the_same_advisory_lock_and_creates_no_hold(): void
    {
        $this->holdAdvisoryLockOnSecondConnection();

        $result = app(CheckoutService::class)->start([
            'start_date' => self::DATE, 'end_date' => '2026-12-20', 'pax_count' => 2, 'class_type' => 'discovery',
            'pickup_option' => 'own', 'total_amount' => 9200, 'downpayment_amount' => 4000,
        ], $this->payload(2, self::DATE));

        $this->assertSame(429, $result['status']);
        $this->assertSame(0, $this->second()->table('checkout_intents')->count(), 'No hold committed while finalization holds the date.');
        Http::assertNothingSent();
    }

    public function test_finalizer_waits_on_the_intent_row_lock_held_by_another_connection(): void
    {
        $intent = $this->intent();
        $this->second()->beginTransaction();
        $this->second()->table('checkout_intents')->where('id', $intent->id)->lockForUpdate()->first();

        $this->assertSame(FinalizationResult::RETRYABLE, $this->finalize($intent)->outcome);
        $this->assertSame(0, Booking::count());

        $this->second()->rollBack();
        $this->assertSame(FinalizationResult::FINALIZED, $this->finalize($intent)->outcome);
        $this->assertSame(1, $this->second()->table('bookings')->count(), 'Committed booking is visible to other connections.');
    }

    public function test_booking_number_collision_is_retried_in_a_savepoint_without_aborting_the_transaction(): void
    {
        Booking::create(['booking_number' => 'CFP-2026-AAAAA', 'pin' => '0000', 'contact_name' => 'Other', 'contact_email' => 'o@example.test',
            'contact_phone' => '09170000004', 'class_type' => 'discovery', 'start_date' => '2026-12-26', 'end_date' => '2026-12-27',
            'status' => 'confirmed', 'total_amount' => 1, 'downpayment_amount' => 1, 'balance_amount' => 0]);
        $suffixes = ['AAAAA', 'BBBBB'];
        Str::createRandomStringsUsing(function (int $length) use (&$suffixes) {
            return $length === 5 && $suffixes ? array_shift($suffixes) : substr(bin2hex(random_bytes($length)), 0, $length);
        });
        $intent = $this->intent();

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::FINALIZED, $result->outcome, $result->reason);
        $this->assertSame('CFP-2026-BBBBB', $result->booking->booking_number);
    }

    public function test_database_constraint_failure_mid_transaction_rolls_back_and_keeps_payment_evidence(): void
    {
        // A participant without an age violates booking_participants.age NOT NULL: PostgreSQL aborts the transaction
        $intent = $this->intent();
        $broken = $intent->payload;
        $broken['participants'][1]['age'] = null;
        $intent->update(['payload' => $broken]);

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::RETRYABLE, $result->outcome);
        $this->assertSame('booking_creation_failed', $result->reason);
        $this->assertSame(0, DB::transactionLevel(), 'No transaction left open after the abort.');
        $this->assertSame(1, CheckoutIntent::count(), 'The connection is usable again (not stuck in an aborted transaction).');
        foreach (['bookings', 'booking_participants', 'payments', 'batches', 'batch_status_logs'] as $table) {
            $this->assertSame(0, $this->second()->table($table)->count(), "{$table} rolled back");
        }
        $this->assertSame(CheckoutIntent::STATUS_PAID, $this->second()->table('checkout_intents')->where('id', $intent->id)->value('status'));

        $fixed = $broken;
        $fixed['participants'][1]['age'] = 31;
        $intent->fresh()->update(['payload' => $fixed]);
        $this->assertSame(FinalizationResult::FINALIZED, $this->finalize($intent)->outcome);
    }

    public function test_hold_committed_by_another_connection_takes_the_last_slots_from_a_late_payment(): void
    {
        $late = $this->intent(2, ['hold_expires_at' => now()->subMinutes(30)]);
        $this->second()->table('checkout_intents')->insert([
            'reference' => 'CHK-PGSQLOTHER01', 'status' => CheckoutIntent::STATUS_PENDING, 'start_date' => self::DATE, 'end_date' => '2026-12-20',
            'pax_count' => 5, 'class_type' => 'discovery', 'pickup_option' => 'own', 'currency' => 'PHP', 'total_amount' => 1, 'downpayment_amount' => 1,
            'payload' => encrypt(json_encode([]), false), 'hold_expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(FinalizationResult::PAID_UNALLOCATED, $this->finalize($late)->outcome);
        $this->assertSame(0, Booking::count());
    }

    public function test_batches_for_two_dates_are_created_separately(): void
    {
        $this->finalize($this->intent());
        $this->finalize($this->intent(2, [], '2026-12-26'));

        $this->assertSame(2, CheckoutIntent::count(), 'No stray intents.');
        $this->assertSame(2, DB::table('batches')->count());
        $this->assertSame(2, Booking::distinct()->count('batch_id'));
    }
}
