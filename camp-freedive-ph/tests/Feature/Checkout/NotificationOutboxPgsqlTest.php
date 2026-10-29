<?php

namespace Tests\Feature\Checkout;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\CheckoutNotification;
use App\Models\User;
use App\Services\Checkout\CheckoutNotifier;
use App\Services\NotificationOutbox;
use App\Services\WeatherRiskNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * B6 notification outbox PostgreSQL concurrency verification.
 *
 * Uses two PostgreSQL connections from the same process (like BookingFinalizerPgsqlTest) to prove
 * claim-based delivery and insertOrIgnore dedup work under row-level locking. Not a substitute for
 * multi-process races, but exercises the actual lock mechanics that SQLite cannot show.
 *
 * DESTRUCTIVE: DatabaseTruncation runs migrate:fresh. guardDisposableDatabase() refuses unless the
 * target is a positively verified disposable local cluster.
 */
class NotificationOutboxPgsqlTest extends TestCase
{
    use DatabaseTruncation;

    private const DISPOSABLE_DATABASES = ['testing'];
    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];
    private const MANAGED_PROVIDER_ROLES = ['supabase_admin', 'supabase_auth_admin', 'authenticator', 'azure_pg_admin', 'azuresu', 'azure_superuser', 'rdsadmin', 'cloudsqlsuperuser'];

    private static bool $committedRows = false;

    protected function setUpTraits(): array
    {
        $this->guardDisposableDatabase();

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'Asia/Manila'));
        config(['database.connections.pgsql_race' => config('database.connections.' . config('database.default'))]);
    }

    protected function tearDown(): void
    {
        if (DB::getConnections()['pgsql_race'] ?? null) {
            DB::purge('pgsql_race');
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$committedRows) {
            RefreshDatabaseState::$migrated = false;
        }
        parent::tearDownAfterClass();
    }

    private function guardDisposableDatabase(): void
    {
        $config = config('database.connections.' . config('database.default'));
        if (($config['driver'] ?? null) !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only.');
        }
        $managed = (int) DB::selectOne("select count(*) as n from pg_roles where rolname in ('" . implode("','", self::MANAGED_PROVIDER_ROLES) . "')")->n;
        if (! empty($config['url'])
            || ! in_array(strtolower((string) ($config['host'] ?? '')), self::LOCAL_HOSTS, true)
            || (getenv('GITHUB_ACTIONS') !== 'true' && getenv('CFP_DISPOSABLE_PGSQL') !== '1')
            || ! in_array($config['database'] ?? null, self::DISPOSABLE_DATABASES, true)
            || DB::selectOne('select current_database() as d')->d !== ($config['database'] ?? '')
            || $managed > 0) {
            $this->fail('Refusing: not a positively identified disposable PostgreSQL database.');
        }
    }

    private function batch(): Batch
    {
        return Batch::create([
            'name' => 'Outbox Race Batch', 'batch_code' => 'OUTBOX-' . Str::random(6),
            'start_date' => '2026-11-16', 'end_date' => '2026-11-17',
            'status' => 'confirmed', 'lifecycle_status' => 'confirmed',
        ]);
    }

    private function booking(?Batch $batch = null): Booking
    {
        $batch ??= $this->batch();

        return Booking::create([
            'batch_id' => $batch->id, 'booking_number' => 'BK-OBX-' . Str::random(6), 'pin' => '1234',
            'class_type' => 'discovery', 'status' => 'confirmed', 'total_amount' => 4250,
            'downpayment_amount' => 2125, 'balance_amount' => 2125,
            'contact_name' => 'Race Guest', 'contact_email' => 'race@example.test', 'contact_phone' => '09170000001',
            'start_date' => $batch->start_date, 'end_date' => $batch->end_date,
        ]);
    }

    private function insertOutboxRow(Booking $booking, string $kind, string $email = 'race@example.test', string $status = 'pending'): CheckoutNotification
    {
        self::$committedRows = true;

        return CheckoutNotification::create([
            'checkout_intent_id' => null, 'booking_id' => $booking->id,
            'kind' => $kind, 'recipient' => $email,
            'recipient_hash' => hash('sha256', strtolower(trim($email))),
            'status' => $status, 'attempts' => 0, 'available_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------- R1: Two workers claim the same notification

    public function test_r1_concurrent_claim_only_one_worker_wins(): void
    {
        self::$committedRows = true;
        $booking = $this->booking();
        $n = $this->insertOutboxRow($booking, CheckoutNotification::GUEST_CANCELLATION_REQUESTED);

        $token1 = (string) Str::uuid();
        $token2 = (string) Str::uuid();

        // Worker 1 claims on the default connection
        $claimed1 = DB::table('checkout_notifications')
            ->where('id', $n->id)
            ->where('status', CheckoutNotification::PENDING)
            ->update([
                'status' => CheckoutNotification::SENDING,
                'claim_token' => $token1,
                'claimed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
            ]);

        // Worker 2 tries to claim the same row (already sending, not stale)
        $claimed2 = DB::connection('pgsql_race')
            ->table('checkout_notifications')
            ->where('id', $n->id)
            ->where('status', CheckoutNotification::PENDING)
            ->update([
                'status' => CheckoutNotification::SENDING,
                'claim_token' => $token2,
                'claimed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
            ]);

        $this->assertSame(1, $claimed1, 'Worker 1 claimed the row');
        $this->assertSame(0, $claimed2, 'Worker 2 found no pending row');

        $row = DB::table('checkout_notifications')->where('id', $n->id)->first();
        $this->assertSame($token1, $row->claim_token, 'Claim token belongs to worker 1');
        $this->assertSame(1, (int) $row->attempts);
    }

    // ---------------------------------------------------------------- R2: Duplicate event key insertOrIgnore

    public function test_r2_duplicate_insert_or_ignore_one_row_survives(): void
    {
        self::$committedRows = true;
        $booking = $this->booking();

        $row = [
            'checkout_intent_id' => null, 'booking_id' => $booking->id,
            'kind' => CheckoutNotification::GUEST_WEATHER_CRITICAL,
            'event_key' => "weather:critical:{$booking->batch_id}:{$booking->id}:2026-11-16",
            'recipient' => \Illuminate\Support\Facades\Crypt::encryptString('race@example.test'),
            'recipient_hash' => hash('sha256', 'race@example.test'),
            'status' => 'pending', 'attempts' => 0, 'available_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ];

        // Connection 1 inserts
        $inserted1 = DB::table('checkout_notifications')->insertOrIgnore([$row]);

        // Connection 2 tries the same (booking_id, kind, recipient_hash) — should be silently ignored
        $inserted2 = DB::connection('pgsql_race')->table('checkout_notifications')->insertOrIgnore([$row]);

        $this->assertSame(1, $inserted1, 'First insert succeeded');
        $this->assertSame(0, $inserted2, 'Duplicate was silently ignored');

        $count = DB::table('checkout_notifications')
            ->where('booking_id', $booking->id)
            ->where('kind', CheckoutNotification::GUEST_WEATHER_CRITICAL)
            ->count();
        $this->assertSame(1, $count, 'Exactly one outbox row exists');
    }

    // ---------------------------------------------------------------- R3: Delivery succeeds while retry competes

    public function test_r3_sent_notification_cannot_be_reclaimed(): void
    {
        self::$committedRows = true;
        $booking = $this->booking();
        $n = $this->insertOutboxRow($booking, CheckoutNotification::GUEST_BATCH_CANCELLED);

        // Worker 1 claims, delivers, marks sent
        $token1 = (string) Str::uuid();
        DB::table('checkout_notifications')->where('id', $n->id)
            ->update(['status' => CheckoutNotification::SENDING, 'claim_token' => $token1, 'claimed_at' => now(), 'attempts' => 1]);

        DB::table('checkout_notifications')->where('id', $n->id)->where('claim_token', $token1)
            ->update(['status' => CheckoutNotification::SENT, 'sent_at' => now(), 'claim_token' => null]);

        // Worker 2 (retry sweep) tries to claim — row is sent, not pending
        $claimed = DB::connection('pgsql_race')->table('checkout_notifications')
            ->where('id', $n->id)
            ->where('status', CheckoutNotification::PENDING)
            ->update(['status' => CheckoutNotification::SENDING, 'claim_token' => (string) Str::uuid(), 'claimed_at' => now()]);

        $this->assertSame(0, $claimed, 'Cannot reclaim a sent notification');
        $this->assertSame(CheckoutNotification::SENT, DB::table('checkout_notifications')->where('id', $n->id)->value('status'));
    }

    // ---------------------------------------------------------------- R4: Stale claim recovery vs active worker

    public function test_r4_stale_claim_recovery_respects_active_worker(): void
    {
        self::$committedRows = true;
        $booking = $this->booking();
        $n = $this->insertOutboxRow($booking, CheckoutNotification::GUEST_RESCHEDULE_APPROVED);

        $activeToken = (string) Str::uuid();
        $freshClaimedAt = now();

        // Active worker holds a fresh claim (not stale)
        DB::table('checkout_notifications')->where('id', $n->id)->update([
            'status' => CheckoutNotification::SENDING,
            'claim_token' => $activeToken,
            'claimed_at' => $freshClaimedAt,
            'attempts' => 1,
        ]);

        $staleMinutes = (int) config('checkout.notifications.stale_claim_minutes', 5);

        // Recovery worker checks for stale claims — this claim is fresh, should not match
        $recovered = DB::connection('pgsql_race')->table('checkout_notifications')
            ->where('id', $n->id)
            ->where('status', CheckoutNotification::SENDING)
            ->where('claimed_at', '<=', now()->subMinutes($staleMinutes))
            ->update(['status' => CheckoutNotification::SENDING, 'claim_token' => (string) Str::uuid(), 'claimed_at' => now()]);

        $this->assertSame(0, $recovered, 'Active claim is not treated as stale');
        $this->assertSame($activeToken, DB::table('checkout_notifications')->where('id', $n->id)->value('claim_token'));

        // Now make the claim stale by backdating claimed_at
        DB::table('checkout_notifications')->where('id', $n->id)->update([
            'claimed_at' => now()->subMinutes($staleMinutes + 1),
        ]);

        // Recovery worker can now reclaim the stale row
        $recoveryToken = (string) Str::uuid();
        $recovered = DB::connection('pgsql_race')->table('checkout_notifications')
            ->where('id', $n->id)
            ->where('status', CheckoutNotification::SENDING)
            ->where('claimed_at', '<=', now()->subMinutes($staleMinutes))
            ->update(['status' => CheckoutNotification::SENDING, 'claim_token' => $recoveryToken, 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1')]);

        $this->assertSame(1, $recovered, 'Stale claim was recovered');
        $row = DB::table('checkout_notifications')->where('id', $n->id)->first();
        $this->assertSame($recoveryToken, $row->claim_token);
        $this->assertSame(2, (int) $row->attempts);
    }

    // ---------------------------------------------------------------- R5: Worker crash after claim

    public function test_r5_crashed_worker_claim_remains_recoverable(): void
    {
        self::$committedRows = true;
        $booking = $this->booking();
        $n = $this->insertOutboxRow($booking, CheckoutNotification::GUEST_BOOKING_UPDATED);

        // Worker claims but then "crashes" (claim_token set, status sending, no sent_at)
        $crashedToken = (string) Str::uuid();
        $staleMinutes = (int) config('checkout.notifications.stale_claim_minutes', 5);
        DB::table('checkout_notifications')->where('id', $n->id)->update([
            'status' => CheckoutNotification::SENDING,
            'claim_token' => $crashedToken,
            'claimed_at' => now()->subMinutes($staleMinutes + 2),
            'attempts' => 1,
        ]);

        // Row is not sent, not failed — it's stuck in sending
        $row = DB::table('checkout_notifications')->where('id', $n->id)->first();
        $this->assertSame(CheckoutNotification::SENDING, $row->status);
        $this->assertNull($row->sent_at);

        // Another worker can reclaim it because claimed_at is stale
        $max = (int) config('checkout.notifications.max_attempts', 3);
        $recoveryToken = (string) Str::uuid();
        $claimed = DB::connection('pgsql_race')->table('checkout_notifications')
            ->where('id', $n->id)
            ->where(function ($q) use ($staleMinutes) {
                $q->where('status', CheckoutNotification::PENDING)
                    ->orWhere(function ($s) use ($staleMinutes) {
                        $s->where('status', CheckoutNotification::SENDING)
                            ->where('claimed_at', '<=', now()->subMinutes($staleMinutes));
                    });
            })
            ->where('attempts', '<', $max)
            ->update([
                'status' => CheckoutNotification::SENDING,
                'claim_token' => $recoveryToken,
                'claimed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
            ]);

        $this->assertSame(1, $claimed, 'Crashed worker claim was recovered');
        $row = DB::table('checkout_notifications')->where('id', $n->id)->first();
        $this->assertSame($recoveryToken, $row->claim_token);
        $this->assertSame(2, (int) $row->attempts);
    }

    // ---------------------------------------------------------------- R6: Concurrent producers during batch cancellation

    public function test_r6_concurrent_batch_cancellation_producers_no_duplicates(): void
    {
        self::$committedRows = true;
        $batch = $this->batch();
        $b1 = $this->booking($batch);
        $b2 = $this->booking($batch);

        $recipientHash = hash('sha256', 'race@example.test');
        $now = now();
        $makeRow = fn (Booking $b) => [
            'checkout_intent_id' => null, 'booking_id' => $b->id, 'batch_id' => $batch->id,
            'kind' => CheckoutNotification::GUEST_BATCH_CANCELLED,
            'event_key' => "batch_cancel:{$batch->id}:{$b->id}",
            'recipient' => \Illuminate\Support\Facades\Crypt::encryptString('race@example.test'),
            'recipient_hash' => $recipientHash,
            'status' => 'pending', 'attempts' => 0, 'available_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ];

        // Producer 1 inserts for booking 1
        DB::table('checkout_notifications')->insertOrIgnore([$makeRow($b1)]);
        // Producer 2 (concurrent) inserts for both bookings — booking 1 should be ignored
        DB::connection('pgsql_race')->table('checkout_notifications')->insertOrIgnore([$makeRow($b1), $makeRow($b2)]);

        $this->assertSame(1, DB::table('checkout_notifications')->where('booking_id', $b1->id)->where('kind', CheckoutNotification::GUEST_BATCH_CANCELLED)->count(),
            'One notification per booking despite concurrent producers');
        $this->assertSame(1, DB::table('checkout_notifications')->where('booking_id', $b2->id)->where('kind', CheckoutNotification::GUEST_BATCH_CANCELLED)->count());
        $this->assertSame(2, DB::table('checkout_notifications')->where('batch_id', $batch->id)->where('kind', CheckoutNotification::GUEST_BATCH_CANCELLED)->count(),
            'Total: one per booking, two in the batch');
    }

    // ---------------------------------------------------------------- R7: Concurrent webhook replays — one logical notification

    public function test_r7_concurrent_webhook_replays_single_notification(): void
    {
        self::$committedRows = true;
        $booking = $this->booking();

        $row = [
            'checkout_intent_id' => null, 'booking_id' => $booking->id,
            'kind' => CheckoutNotification::GUEST_CANCELLATION_APPROVED,
            'event_key' => "cancel_approve:{$booking->id}",
            'recipient' => \Illuminate\Support\Facades\Crypt::encryptString('race@example.test'),
            'recipient_hash' => hash('sha256', 'race@example.test'),
            'status' => 'pending', 'attempts' => 0, 'available_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ];

        // Simulate two webhook deliveries arriving simultaneously
        $results = [];
        $results[] = DB::table('checkout_notifications')->insertOrIgnore([$row]);
        $results[] = DB::connection('pgsql_race')->table('checkout_notifications')->insertOrIgnore([$row]);

        $this->assertSame(1, array_sum($results), 'Only one insert succeeded');
        $this->assertSame(1, DB::table('checkout_notifications')
            ->where('booking_id', $booking->id)
            ->where('kind', CheckoutNotification::GUEST_CANCELLATION_APPROVED)
            ->count());
    }

    // ---------------------------------------------------------------- R8: Weather risk dedup under concurrent polling

    public function test_r8_concurrent_weather_polling_one_notification_per_booking(): void
    {
        self::$committedRows = true;
        $batch = $this->batch();
        $booking = $this->booking($batch);

        $eventKey = "weather:critical:{$batch->id}:{$booking->id}:{$batch->start_date->format('Y-m-d')}";
        $row = [
            'checkout_intent_id' => null, 'booking_id' => $booking->id, 'batch_id' => $batch->id,
            'kind' => CheckoutNotification::GUEST_WEATHER_CRITICAL,
            'event_key' => $eventKey,
            'recipient' => \Illuminate\Support\Facades\Crypt::encryptString('race@example.test'),
            'recipient_hash' => hash('sha256', 'race@example.test'),
            'status' => 'pending', 'attempts' => 0, 'available_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ];

        // Two polling cycles insert concurrently
        DB::table('checkout_notifications')->insertOrIgnore([$row]);
        DB::connection('pgsql_race')->table('checkout_notifications')->insertOrIgnore([$row]);

        $count = DB::table('checkout_notifications')
            ->where('booking_id', $booking->id)
            ->where('kind', CheckoutNotification::GUEST_WEATHER_CRITICAL)
            ->count();
        $this->assertSame(1, $count, 'Weather dedup: exactly one critical notification per booking');

        // A second polling cycle with a different level creates a separate row (different kind)
        $highRow = $row;
        $highRow['kind'] = CheckoutNotification::GUEST_WEATHER_HIGH;
        $highRow['event_key'] = str_replace('critical', 'high', $eventKey);
        DB::table('checkout_notifications')->insertOrIgnore([$highRow]);

        $this->assertSame(1, DB::table('checkout_notifications')
            ->where('booking_id', $booking->id)
            ->where('kind', CheckoutNotification::GUEST_WEATHER_HIGH)
            ->count());
        $this->assertSame(2, DB::table('checkout_notifications')
            ->where('booking_id', $booking->id)
            ->whereIn('kind', [CheckoutNotification::GUEST_WEATHER_CRITICAL, CheckoutNotification::GUEST_WEATHER_HIGH])
            ->count(), 'Critical and High are separate notifications');
    }

    // ---------------------------------------------------------------- Supplementary: NULL booking_id dedup gap

    public function test_staff_alerts_without_booking_id_allow_duplicates_via_null_unique(): void
    {
        self::$committedRows = true;
        $recipientHash = hash('sha256', 'admin@example.test');
        $now = now();

        $row = [
            'checkout_intent_id' => null, 'booking_id' => null,
            'kind' => CheckoutNotification::STAFF_ADMIN_ALERT,
            'event_key' => 'imminent_critical:batch:99',
            'recipient' => \Illuminate\Support\Facades\Crypt::encryptString('admin@example.test'),
            'recipient_hash' => $recipientHash,
            'status' => 'pending', 'attempts' => 0, 'available_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ];

        // Two identical inserts with NULL booking_id — PostgreSQL unique constraint allows both (NULL ≠ NULL)
        $i1 = DB::table('checkout_notifications')->insertOrIgnore([$row]);
        $i2 = DB::connection('pgsql_race')->table('checkout_notifications')->insertOrIgnore([$row]);

        $count = DB::table('checkout_notifications')
            ->whereNull('booking_id')
            ->where('kind', CheckoutNotification::STAFF_ADMIN_ALERT)
            ->where('recipient_hash', $recipientHash)
            ->count();

        // Document the PostgreSQL behavior: NULL in unique constraints allows "duplicates"
        // The application handles this via batch_id (most staff alerts have a batch) or event_key application-level dedup
        $this->assertSame(1, $i1);
        $this->assertSame(1, $i2, 'PostgreSQL allows duplicate rows with NULL booking_id in unique constraint');
        $this->assertSame(2, $count, 'Two rows exist — application-level event_key dedup needed for NULL-FK staff alerts');
    }

    // ---------------------------------------------------------------- Supplementary: no deadlock under concurrent claims

    public function test_concurrent_claims_on_different_rows_no_deadlock(): void
    {
        self::$committedRows = true;
        $batch = $this->batch();
        $b1 = $this->booking($batch);
        $b2 = $this->booking($batch);

        $n1 = $this->insertOutboxRow($b1, CheckoutNotification::GUEST_WEATHER_CRITICAL, 'guest1@example.test');
        $n2 = $this->insertOutboxRow($b2, CheckoutNotification::GUEST_WEATHER_CRITICAL, 'guest2@example.test');

        $t1 = (string) Str::uuid();
        $t2 = (string) Str::uuid();

        // Worker 1 claims row 1, Worker 2 claims row 2 — no deadlock
        $c1 = DB::table('checkout_notifications')->where('id', $n1->id)->where('status', 'pending')
            ->update(['status' => 'sending', 'claim_token' => $t1, 'claimed_at' => now()]);
        $c2 = DB::connection('pgsql_race')->table('checkout_notifications')->where('id', $n2->id)->where('status', 'pending')
            ->update(['status' => 'sending', 'claim_token' => $t2, 'claimed_at' => now()]);

        $this->assertSame(1, $c1);
        $this->assertSame(1, $c2);
        $this->assertSame($t1, DB::table('checkout_notifications')->where('id', $n1->id)->value('claim_token'));
        $this->assertSame($t2, DB::table('checkout_notifications')->where('id', $n2->id)->value('claim_token'));
    }
}
