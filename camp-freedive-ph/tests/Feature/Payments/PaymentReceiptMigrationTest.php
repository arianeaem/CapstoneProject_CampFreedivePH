<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\RefundAttempt;
use App\Services\Payment\PaymentExceptionService;
use App\Services\Payment\ProviderPayment;
use App\Services\Payment\ReceiptRecorder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\Concerns\BuildsCheckoutIntents;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-II B1 migrations over existing financial data, on SQLite (table rebuild with integrity checks) and on
 * PostgreSQL (transactional DDL): counts and money totals unchanged, historical rows untouched, guards installed, and
 * every rollback that would lose verified money refused.
 *
 * DESTRUCTIVE: runs migrate:fresh and rollbacks. Only on an in-memory SQLite database or a positively identified
 * disposable local PostgreSQL database (same checks as BookingFinalizerPgsqlTest); anything else fails before any
 * migration. The next database test in the process rebuilds its database (RefreshDatabaseState reset).
 */
class PaymentReceiptMigrationTest extends TestCase
{
    use BuildsCheckoutIntents;
    use RefundFixtures;

    /** Migrations added after B1 (B2.1 guest choices) sit on top: rollbacks to B1 steps pass through them first. */
    private const LATER_MIGRATIONS = 1;
    private const B1_MIGRATIONS = 3 + self::LATER_MIGRATIONS;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardDisposableDatabase();
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    private function guardDisposableDatabase(): void
    {
        $config = (array) config('database.connections.' . config('database.default'));
        if (($config['driver'] ?? null) === 'sqlite') {
            if (($config['database'] ?? null) !== ':memory:') {
                $this->fail('Refusing migration test: SQLite database is not in-memory.');
            }

            return;
        }
        if (($config['driver'] ?? null) !== 'pgsql') {
            $this->markTestSkipped('SQLite in-memory or disposable PostgreSQL only.');
        }
        $managed = (int) DB::selectOne("select count(*) as n from pg_roles where rolname in ('supabase_admin','supabase_auth_admin','authenticator','azure_pg_admin','azuresu','azure_superuser','rdsadmin','cloudsqlsuperuser')")->n;
        if (! empty($config['url']) || ! in_array(strtolower((string) ($config['host'] ?? '')), ['127.0.0.1', 'localhost', '::1'], true)
            || (getenv('GITHUB_ACTIONS') !== 'true' && getenv('CFP_DISPOSABLE_PGSQL') !== '1')
            || ($config['database'] ?? null) !== 'testing' || DB::selectOne('select current_database() as d')->d !== 'testing' || $managed > 0) {
            $this->fail('Refusing migration test: not a positively identified disposable PostgreSQL database.');
        }
    }

    private function rollback(int $steps): ?string
    {
        try {
            Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function snapshot(): array
    {
        return [
            'payments' => DB::table('payments')->orderBy('id')->get(['id', 'booking_id', 'amount', 'status', 'paymongo_payment_id', 'amount_refunded', 'transaction_id'])
                ->map(fn ($r) => (array) $r)->all(),
            'sum' => (string) DB::table('payments')->sum('amount'),
            'refund_attempts' => DB::table('refund_attempts')->orderBy('id')->pluck('amount_centavos', 'payment_id')->all(),
            'payment_status_logs' => DB::table('payment_status_logs')->count(),
        ];
    }

    private function triggerNames(): array
    {
        $names = DB::getDriverName() === 'pgsql'
            ? collect(DB::select("select tgname as name from pg_trigger where not tgisinternal"))->pluck('name')
            : collect(DB::select("select name from sqlite_master where type = 'trigger'"))->pluck('name');

        return $names->sort()->values()->all();
    }

    public function test_migrations_keep_existing_financial_records_identical_and_install_the_guards(): void
    {
        $this->assertNull($this->rollback(self::B1_MIGRATIONS));
        $booking = $this->booking();
        $paid = $this->receipt($booking, 2000, 'paid', 'pay_mig_dup');
        $this->receipt($this->booking(), 2000, 'paid', 'pay_mig_dup');                     // historical duplicate id
        $this->receipt($booking, 4250, 'partially_refunded', 'pay_mig_ref', 'full', ['amount_refunded' => 1000]);
        $this->receipt($booking, 1999.99, 'completed', null);
        DB::table('payments')->insert(['booking_id' => $booking->id, 'payment_method' => 'paymongo', 'transaction_id' => 'TXN-MIG-PENDING', 'amount' => 2000,
            'payment_type' => 'downpayment', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        RefundAttempt::create(['payment_id' => $paid->id, 'operation_id' => (string) Str::uuid(), 'method' => 'paymongo', 'amount_centavos' => 50000,
            'currency' => 'PHP', 'status' => RefundAttempt::UNKNOWN]);
        DB::table('payment_status_logs')->insert(['payment_id' => $paid->id, 'old_status' => 'new', 'new_status' => 'paid', 'created_at' => now()]);
        $before = $this->snapshot();

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('payments')->whereNotNull('checkout_intent_id')->count());
        $this->assertSame(2, DB::table('payments')->where('paymongo_payment_id', 'pay_mig_dup')->count(), 'duplicates kept, never merged');
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        }
        $this->assertEqualsCanonicalizing(array_merge(
            DB::getDriverName() === 'pgsql' ? ['payment_exception_choices_guard'] // B2.1
                : ['payment_exception_choices_valid_insert', 'payment_exception_choices_guard_update', 'payment_exception_choices_no_delete'],
            DB::getDriverName() === 'pgsql'
                ? ['payments_guard_owner', 'provider_payment_identities_guard', 'payment_exceptions_guard', 'payment_exception_receipts_immutable', 'payment_exception_events_immutable', 'payment_exception_receipts_owner']
                : ['payments_owner_present', 'payments_guard_owner', 'provider_payment_identities_no_delete', 'provider_payment_identities_guard', 'payment_exceptions_no_delete', 'payment_exceptions_guard',
                    'payment_exception_receipts_no_update', 'payment_exception_receipts_no_delete', 'payment_exception_events_no_update', 'payment_exception_events_no_delete', 'payment_exception_receipts_owner'],
        ), $this->triggerNames());

        // Receipts are RESTRICTed to their booking now
        $deleted = null;
        try {
            DB::table('bookings')->where('id', $booking->id)->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            $deleted = $e;
        }
        $this->assertNotNull($deleted);
        $this->assertSame($before, $this->snapshot());

        // A clean rollback (no B1 data yet) restores the old schema with the records unchanged
        $this->assertNull($this->rollback(self::B1_MIGRATIONS));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
    }

    public function test_rollback_is_refused_while_exceptions_identities_or_unapplied_receipts_exist(): void
    {
        $intent = $this->checkoutIntent();
        $unapplied = DB::transaction(fn () => app(ReceiptRecorder::class)->record(new ProviderPayment('paymongo', 'pay_mig_unapplied', 400000, 'PHP', false),
            $intent->id, null, 'test', fn () => Payment::create(['booking_id' => null, 'checkout_intent_id' => $intent->id, 'payment_method' => 'gcash',
                'transaction_id' => 'PAY-MIG-1', 'paymongo_payment_id' => 'pay_mig_unapplied', 'amount' => 4000, 'payment_type' => 'downpayment', 'status' => 'paid'])))->payment;
        DB::transaction(fn () => app(PaymentExceptionService::class)->open(PaymentException::KIND_INTENT_UNALLOCATED, $unapplied, 'test'));

        $this->assertStringContainsString('Payment exceptions are recorded', (string) $this->rollback(self::LATER_MIGRATIONS + 1));
        $this->assertStringContainsString('Payment exceptions are recorded', (string) $this->rollback(self::B1_MIGRATIONS));
        $this->assertSame(1, PaymentException::count());
        $this->assertSame(1, DB::table('provider_payment_identities')->count());
        $this->assertNull(Payment::find($unapplied->id)->booking_id);
    }

    public function test_each_rollback_step_refuses_on_its_own_data(): void
    {
        $intent = $this->checkoutIntent();
        $booking = $this->booking();
        DB::transaction(fn () => app(ReceiptRecorder::class)->record(new ProviderPayment('paymongo', 'pay_mig_identity', 200000, 'PHP', false),
            null, $booking->id, 'test', fn () => Payment::create(['booking_id' => $booking->id, 'payment_method' => 'gcash', 'transaction_id' => 'PAY-MIG-2',
                'paymongo_payment_id' => 'pay_mig_identity', 'amount' => 2000, 'payment_type' => 'downpayment', 'status' => 'paid'])));

        $this->assertStringContainsString('Provider payment identities or conflicts are recorded', (string) $this->rollback(self::LATER_MIGRATIONS + 2));
        $this->assertSame(1, DB::table('provider_payment_identities')->count());

        // Payments-only step: an unapplied receipt without identity still blocks the payments rollback
        Artisan::call('migrate:fresh', ['--force' => true]);
        $intent = $this->checkoutIntent();
        DB::table('payments')->insert(['booking_id' => null, 'checkout_intent_id' => $intent->id, 'payment_method' => 'gcash', 'transaction_id' => 'PAY-MIG-3',
            'amount' => 4000, 'payment_type' => 'downpayment', 'status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertStringContainsString('Some payments have no booking', (string) $this->rollback(self::B1_MIGRATIONS));
        $this->assertSame(1, DB::table('payments')->whereNull('booking_id')->count());
    }

    /** An unapplied checkout receipt with its open exception, as B1 records it. */
    private function openIntentException(string $payId): PaymentException
    {
        $intent = $this->checkoutIntent();
        $receipt = DB::transaction(fn () => app(ReceiptRecorder::class)->record(new ProviderPayment('paymongo', $payId, 400000, 'PHP', false),
            $intent->id, null, 'test', fn () => Payment::create(['booking_id' => null, 'checkout_intent_id' => $intent->id, 'payment_method' => 'gcash',
                'transaction_id' => 'PAY-' . strtoupper(Str::random(8)), 'paymongo_payment_id' => $payId, 'amount' => 4000, 'payment_type' => 'downpayment', 'status' => 'paid'])))->payment;
        $intent->forceFill(['status' => \App\Models\CheckoutIntent::STATUS_PAID_UNALLOCATED])->save();

        return DB::transaction(fn () => app(PaymentExceptionService::class)->open(PaymentException::KIND_INTENT_UNALLOCATED, $receipt, 'test'));
    }

    public function test_the_guest_choice_migration_keeps_existing_exceptions_and_refuses_rollback_once_choices_exist(): void
    {
        // B2.1 applied over existing B1 exceptions: nothing about them changes
        $this->assertNull($this->rollback(self::LATER_MIGRATIONS));
        $exception = $this->openIntentException('pay_mig_choice');
        $before = [DB::table('payment_exceptions')->get()->map(fn ($r) => (array) $r)->all(), DB::table('payment_exception_events')->count(), DB::table('payments')->count()];
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame($before, [DB::table('payment_exceptions')->get()->map(fn ($r) => (array) $r)->all(), DB::table('payment_exception_events')->count(), DB::table('payments')->count()]);

        // Without choices it rolls back cleanly; once a choice exists the rollback is refused and the choice kept
        $this->assertNull($this->rollback(self::LATER_MIGRATIONS));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $staff = fn () => \App\Models\User::factory()->create(['role' => 'admin', 'status' => 'active', 'must_change_password' => false, 'email' => 'mig.' . uniqid() . '@example.test']);
        app(\App\Services\Payment\GuestChoiceService::class)->record($exception, $staff(), ['choice' => 'refund_review', 'evidence_channel' => 'email',
            'evidence_at' => now()->subHour()->toIso8601String(), 'evidence_summary' => 'Synthetic: guest asked for a refund review.',
            'contact_match_method' => 'email_address', 'contact_match_outcome' => 'matched']);
        $this->assertStringContainsString('Guest choices are recorded', (string) $this->rollback(self::LATER_MIGRATIONS));
        $this->assertSame(1, DB::table('payment_exception_choices')->count());
    }
}
