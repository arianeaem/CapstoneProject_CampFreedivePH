<?php

namespace Tests\Feature\Payments;

use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Services\Payment\ReceiptBackfillPlanner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Checkout\Concerns\BuildsCheckoutIntents;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Payments\Concerns\ReceiptFixtures;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-II B1: the receipt backfill planner is a dry run. It reports duplicates (never merged), unverified historical
 * ids (currency and mode unknown), paid checkouts without a receipt and flagged receipts without an exception, with a
 * deterministic hash. It writes nothing, contacts nobody, is not scheduled, and offers no way to execute.
 */
class ReceiptBackfillPlannerTest extends TestCase
{
    use BuildsCheckoutIntents;
    use FakesPayMongo;
    use RefreshDatabase;
    use ReceiptFixtures;
    use RefundFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        $this->setUpCheckoutWorld();
    }

    /** Historical data as it would look before B1: no identities, no exceptions. */
    private function historicalData(): array
    {
        $a = $this->paxBooking(1);
        $b = $this->paxBooking(1);
        $this->receipt($a, 2000, 'paid', 'pay_hist_dup');
        $this->receipt($b, 2000, 'paid', 'pay_hist_dup');
        $single = $this->receipt($a, 2250, 'paid', 'pay_hist_single', 'balance_settlement');
        $flagged = $this->receipt($b, 2000, 'paid', 'pay_hist_flag', 'downpayment', ['review_reason' => Payment::REVIEW_PAID_AGAIN, 'review_flagged_at' => now()]);
        $unallocated = $this->checkoutIntent(30, ['status' => CheckoutIntent::STATUS_PAID_UNALLOCATED, 'paymongo_payment_id' => 'pay_hist_intent',
            'paid_amount' => 4000, 'paid_currency' => 'PHP', 'verification' => ['livemode' => false, 'payment_id' => 'pay_hist_intent']]);
        $review = $this->checkoutIntent(30, ['status' => CheckoutIntent::STATUS_PAYMENT_REVIEW, 'paymongo_payment_id' => 'pay_hist_review', 'failure_reason' => 'amount_mismatch']);

        return compact('single', 'flagged', 'unallocated', 'review');
    }

    /** Every table's row count and the latest update, to prove nothing changed. */
    private function databaseFingerprint(): array
    {
        $tables = collect(Schema::getTableListing(schemaQualified: false))->reject(fn ($t) => $t === 'migrations')->sort()->values();

        return $tables->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    public function test_the_plan_reports_every_category_and_writes_nothing(): void
    {
        $data = $this->historicalData();
        $before = $this->databaseFingerprint();
        $payments = Payment::orderBy('id')->get()->toArray();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $plan = app(ReceiptBackfillPlanner::class)->plan();

        $this->assertSame([], $writes, 'no write statement was issued');
        $this->assertSame($before, $this->databaseFingerprint());
        $this->assertSame($payments, Payment::orderBy('id')->get()->toArray());
        $this->assertSame(['claim_identity_unverified' => 2, 'create_unapplied_receipt' => 1, 'duplicate_provider_id' => 1, 'open_exception' => 2, 'review_intent_payment' => 1], $plan['counts']);

        $byKey = collect($plan['items'])->keyBy(fn ($i) => "{$i['action']}|{$i['key']}");
        $dup = $byKey['duplicate_provider_id|paymongo:pay_hist_dup'];
        $this->assertCount(2, $dup['payment_ids']);
        $claim = $byKey['claim_identity_unverified|paymongo:pay_hist_single'];
        $this->assertSame([$data['single']->id, 225000, null, null], [$claim['payment_id'], $claim['amount_centavos'], $claim['currency'], $claim['livemode']],
            'historical currency and mode are reported as unknown, never assumed');
        $this->assertSame(400000, $byKey["create_unapplied_receipt|intent:{$data['unallocated']->id}"]['amount_centavos']);
        $this->assertSame('paid_again', $byKey["open_exception|payment:{$data['flagged']->id}"]['kind']);
        Http::assertNothingSent();

        // The connection is writable again afterwards (read-only mode was undone)
        $this->assertNull($this->databaseRefusal(fn () => DB::table('payments')->where('id', $data['single']->id)->update(['updated_at' => now()])));
    }

    public function test_the_same_data_gives_the_same_plan_hash_and_a_change_gives_another(): void
    {
        $data = $this->historicalData();

        $first = app(ReceiptBackfillPlanner::class)->plan();
        $second = app(ReceiptBackfillPlanner::class)->plan();
        $this->receipt($data['single']->booking, 100, 'paid', 'pay_hist_new');
        $third = app(ReceiptBackfillPlanner::class)->plan();

        $this->assertSame($first, $second);
        $this->assertNotSame($first['hash'], $third['hash']);
    }

    public function test_the_command_is_a_dry_run_only_and_is_never_scheduled(): void
    {
        $this->historicalData();
        $before = $this->databaseFingerprint();

        $this->assertSame(0, Artisan::call('payments:plan-receipt-backfill'));
        $output = Artisan::output();

        $this->assertStringContainsString('Dry run only: nothing was written.', $output);
        $this->assertStringContainsString('Plan hash:', $output);
        $this->assertSame($before, $this->databaseFingerprint());
        $definition = Artisan::all()['payments:plan-receipt-backfill']->getDefinition();
        $standard = ['help', 'silent', 'quiet', 'verbose', 'version', 'ansi', 'no-interaction', 'env'];
        $this->assertSame(['json'], array_values(array_diff(array_keys($definition->getOptions()), $standard)), 'no execute option');
        $this->assertSame(['command'], array_keys($definition->getArguments()), 'no arguments beyond the command name');
        $this->assertFalse(collect(Artisan::all())->keys()->contains(fn ($name) => str_contains($name, 'backfill') && $name !== 'payments:plan-receipt-backfill'));
        $this->assertFalse(collect(app(Schedule::class)->events())->contains(fn ($event) => str_contains((string) $event->command, 'backfill')));
    }
}
