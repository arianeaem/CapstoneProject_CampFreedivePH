<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\CoachRequest;
use App\Models\CoachOpening;
use App\Models\Participant;
use App\Models\ParticipantAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Access-control audit (AC-01 .. AC-07).
 *
 * Every test asserts the SECURE expected behaviour. A failing test is a
 * demonstrated authorization defect, not a broken test. Synthetic records only.
 */
class AccessControlAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $admin;
    protected User $coachA;
    protected User $coachB;
    protected Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $make = fn (string $role, string $email) => User::factory()->create([
            'role' => $role, 'email' => $email, 'status' => 'active', 'must_change_password' => false,
        ]);
        $this->owner = $make('owner', 'owner.audit@example.test');
        $this->admin = $make('admin', 'admin.audit@example.test');
        $this->coachA = $make('coach', 'coach.a@example.test');
        $this->coachB = $make('coach', 'coach.b@example.test');

        $start = Carbon::now()->addDays(14)->startOfDay();
        $this->batch = Batch::create([
            'batch_code' => 'BAT-AUDIT-1',
            'start_date' => $start,
            'end_date' => $start->copy()->addDay(),
            'lifecycle_status' => 'open_for_booking',
            'max_capacity' => 12,
        ]);
    }

    private function booking(string $number, string $pin, array $attrs = []): Booking
    {
        $b = Booking::create(array_merge([
            'batch_id' => $this->batch->id,
            'booking_number' => $number,
            'pin' => $pin,
            'start_date' => $this->batch->start_date,
            'end_date' => $this->batch->end_date,
            'contact_name' => 'Synthetic Contact',
            'contact_email' => strtolower($number) . '@example.test',
            'contact_phone' => '09170000000',
            'class_type' => 'discovery',
            'student_count' => 1,
            'downpayment_amount' => 3000,
            'balance_amount' => 1250,
            'total_price' => 4250,
            'status' => 'confirmed',
        ], $attrs));
        $b->participants()->create([
            'name' => 'Synthetic Diver', 'birthdate' => '1990-01-01', 'age' => 36, 'gender' => 'female',
            'health_condition' => 'SYNTHETIC-HEALTH-MARKER', 'swimmer_status' => 'swimmer', 'price_per_person' => 4250,
        ]);

        return $b;
    }

    // ---------------------------------------------------------------- AC-04 unauthenticated

    /** ACT-001 */
    public function test_guest_is_redirected_from_every_staff_portal(): void
    {
        foreach (['/owner', '/admin', '/coach', '/owner/bookings', '/admin/settings/users', '/coach/my-schedule', '/profile'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    /** ACT-002 */
    public function test_guest_cannot_call_staff_mutations_directly(): void
    {
        $booking = $this->booking('CFP-2026-AAAA1', '1111');
        $this->patch("/admin/bookings/{$booking->id}/status", ['status' => 'cancelled'])->assertRedirect(route('login'));
        $this->post('/admin/settings/users', ['role' => 'admin'])->assertRedirect(route('login'));
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    /** ACT-003 */
    public function test_ml_api_rejects_missing_and_wrong_token(): void
    {
        config(['services.ml.token' => 'synthetic-ml-token']);
        $this->getJson('/api/v1/ml/training-data')->assertStatus(401);
        $this->getJson('/api/v1/ml/training-data', ['Authorization' => 'Bearer wrong'])->assertStatus(401);
    }

    // ---------------------------------------------------------------- AC-03 role separation

    /** ACT-010 */
    public function test_coach_is_forbidden_from_backoffice_endpoints(): void
    {
        $booking = $this->booking('CFP-2026-AAAA2', '2222');
        $this->actingAs($this->coachA)->get('/admin/bookings')->assertForbidden();
        $this->actingAs($this->coachA)->get("/owner/bookings/{$booking->id}")->assertForbidden();
        $this->actingAs($this->coachA)->get('/admin/participants/export')->assertForbidden();
        $this->actingAs($this->coachA)->patch("/admin/bookings/{$booking->id}/status", ['status' => 'cancelled'])->assertForbidden();
    }

    /** ACT-011 */
    public function test_admin_cannot_reach_owner_only_settings_or_audit_logs(): void
    {
        $this->actingAs($this->admin)->get('/admin/settings')->assertForbidden();
        $this->actingAs($this->admin)->get('/owner/audit-logs')->assertForbidden();
        $this->actingAs($this->admin)->put('/admin/settings/deposits', [])->assertForbidden();
    }

    /** ACT-012 */
    public function test_admin_cannot_export_revenue(): void
    {
        $this->actingAs($this->admin)->get('/admin/reports/export?type=revenue')->assertForbidden();
    }

    /** ACT-013 */
    public function test_admin_cannot_escalate_a_coach_to_owner_or_edit_an_owner(): void
    {
        $payload = ['first_name' => 'Coach', 'last_name' => 'A', 'no_middle_name' => 1, 'email' => $this->coachA->email,
            'phone' => '09170000000', 'role' => 'owner', 'status' => 'active'];
        $this->actingAs($this->admin)->put("/admin/settings/users/{$this->coachA->id}", $payload)->assertSessionHasErrors('role');
        $this->assertSame('coach', $this->coachA->fresh()->role);

        $this->actingAs($this->admin)->put("/admin/settings/users/{$this->owner->id}", array_merge($payload, ['email' => $this->owner->email, 'role' => 'coach']))
            ->assertForbidden();
        $this->assertSame('owner', $this->owner->fresh()->role);
    }

    /** ACT-014 */
    public function test_role_field_injected_by_client_is_ignored_on_login(): void
    {
        $this->post('/login', ['email' => $this->coachA->email, 'password' => 'password', 'role' => 'owner']);
        $this->get('/owner')->assertForbidden();
    }

    // ---------------------------------------------------------------- AC-05 coach isolation

    /** ACT-020 */
    public function test_coach_cannot_view_participant_of_another_coach(): void
    {
        $booking = $this->booking('CFP-2026-AAAA3', '3333');
        $bp = $booking->participants->first();
        ParticipantAssignment::create(['participant_id' => $bp->id, 'booking_id' => $booking->id, 'batch_id' => $this->batch->id,
            'coach_id' => $this->coachA->id, 'dive_date' => $booking->start_date, 'assigned_by' => $this->admin->id, 'status' => 'assigned']);

        $this->actingAs($this->coachA)->get(route('coach.participants.show', $bp->participant_id))->assertOk();
        $this->actingAs($this->coachB)->get(route('coach.participants.show', $bp->participant_id))->assertForbidden();
    }

    /** ACT-021 */
    public function test_coach_loses_participant_access_after_being_reassigned_away(): void
    {
        $booking = $this->booking('CFP-2026-AAAA4', '4444');
        $bp = $booking->participants->first();
        ParticipantAssignment::create(['participant_id' => $bp->id, 'booking_id' => $booking->id, 'batch_id' => $this->batch->id,
            'coach_id' => $this->coachA->id, 'dive_date' => $booking->start_date, 'assigned_by' => $this->admin->id, 'status' => 'reassigned']);

        $this->actingAs($this->coachA)->get(route('coach.participants.show', $bp->participant_id))->assertForbidden();
    }

    /** ACT-022 */
    public function test_coach_cannot_withdraw_another_coachs_request(): void
    {
        $opening = CoachOpening::create(['batch_id' => $this->batch->id, 'dive_date' => $this->batch->start_date,
            'needed_students_count' => 1, 'status' => 'open', 'posted_by' => $this->admin->id]);
        $req = CoachRequest::create(['opening_id' => $opening->id, 'batch_id' => $this->batch->id, 'coach_id' => $this->coachA->id, 'status' => 'pending']);

        $this->actingAs($this->coachB)->delete(route('coach.requests.withdraw', $req))->assertForbidden();
        $this->assertDatabaseHas('coach_requests', ['id' => $req->id]);
    }

    /** ACT-023 */
    public function test_coach_cannot_file_release_request_for_batch_they_are_not_assigned_to(): void
    {
        $this->actingAs($this->coachB)->post(route('coach.availability.release'), [
            'batch_id' => $this->batch->id, 'dive_date' => $this->batch->start_date->toDateString(), 'reason' => 'Synthetic unrelated release request',
        ]);
        $this->assertDatabaseMissing('assignment_release_requests', ['coach_id' => $this->coachB->id, 'batch_id' => $this->batch->id]);
    }

    // ---------------------------------------------------------------- AC-05 / AC-06 guest booking isolation

    /** ACT-030 */
    public function test_manage_booking_requires_matching_pin(): void
    {
        $a = $this->booking('CFP-2026-AAAA5', '5555');
        $this->get("/manage-booking/{$a->booking_number}")->assertRedirect();
        $this->get("/manage-booking/{$a->booking_number}?pin=0000")->assertRedirect();
        $this->get("/manage-booking/{$a->booking_number}?pin=5555")->assertOk()->assertSee('SYNTHETIC-HEALTH-MARKER');
    }

    /** ACT-031 */
    public function test_session_for_customer_a_does_not_open_customer_b(): void
    {
        $a = $this->booking('CFP-2026-AAAA6', '6666');
        $b = $this->booking('CFP-2026-BBBB6', '7777');
        $this->withSession(['auth_booking_id' => $a->id, 'auth_booking_pin' => '6666'])
            ->get("/manage-booking/{$b->booking_number}")->assertRedirect();
        $this->withSession(['auth_booking_id' => $a->id])->get(route('manage.book_again', $b->booking_number))->assertRedirect(route('manage.index'));
    }

    /** ACT-032 */
    public function test_customer_a_pin_cannot_cancel_customer_b(): void
    {
        $this->booking('CFP-2026-AAAA7', '8888');
        $b = $this->booking('CFP-2026-BBBB7', '9999');
        $this->post("/manage-booking/{$b->booking_number}/cancel", ['pin' => '8888', 'confirm_cancel_ack' => '1'])->assertNotFound();
        $this->assertSame('confirmed', $b->fresh()->status);
    }

    /** ACT-033 (regression for audit finding: /book?confirmed= leaks PIN without PIN) */
    public function test_booking_confirmation_page_does_not_reveal_pin_from_reference_alone(): void
    {
        $b = $this->booking('CFP-2026-CCCC1', '4321');
        $this->get('/book?confirmed=' . $b->booking_number)
            ->assertDontSee('4321')
            ->assertDontSee('SYNTHETIC-HEALTH-MARKER');
    }

    /** ACT-034 (regression for audit finding: payment cancel route grants session by numeric id) */
    public function test_paymongo_cancel_route_does_not_grant_access_by_numeric_id(): void
    {
        $victim = $this->booking('CFP-2026-CCCC2', '2468');
        $response = $this->get("/booking/{$victim->id}/paymongo/cancel");

        $response->assertSessionMissing('auth_booking_id');
        $this->assertStringNotContainsString('2468', (string) $response->headers->get('Location'));
    }

    /** ACT-035 (regression for audit finding: success route confirms unpaid booking for anyone) */
    public function test_paymongo_success_route_does_not_confirm_unpaid_booking(): void
    {
        $victim = $this->booking('CFP-2026-CCCC3', '1357', ['status' => 'pending_downpayment']);
        $response = $this->get("/booking/{$victim->id}/paymongo/success");

        $this->assertSame('pending_downpayment', $victim->fresh()->status);
        $response->assertSessionMissing('auth_booking_id');
        $this->assertStringNotContainsString('1357', (string) $response->headers->get('Location'));
    }

    /** ACT-036 (regression for audit finding: GET detail route is not rate limited) */
    public function test_pin_guessing_on_detail_route_is_rate_limited(): void
    {
        $b = $this->booking('CFP-2026-CCCC4', '9753');
        $throttled = false;
        for ($i = 0; $i < 30; $i++) {
            $pin = str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            if ($this->getJson("/manage-booking/{$b->booking_number}?pin={$pin}")->status() === 429) {
                $throttled = true;
                break;
            }
        }
        $this->assertTrue($throttled, '30 wrong PINs on GET /manage-booking/{number}?pin= were never throttled');
    }

    /** ACT-037 */
    public function test_pin_lookup_post_is_rate_limited(): void
    {
        $b = $this->booking('CFP-2026-CCCC5', '8642');
        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->postJson("/manage-booking/search", ["booking_number" => $b->booking_number, "pin" => "0000"])->status();
        }
        $this->assertNotSame(429, $statuses[0]);
        $this->assertSame(429, end($statuses), "lookup was not throttled after 12 attempts");
    }

    /** ACT-038 (regression for audit finding: webhook accepts unsigned events when secret is empty) */
    public function test_unsigned_webhook_is_rejected_even_without_configured_secret(): void
    {
        config(['paymongo.webhook_signature_secret' => '', 'services.paymongo.webhook_secret' => '']);
        $victim = $this->booking('CFP-2026-CCCC6', '1122', ['status' => 'pending_downpayment']);
        $payload = ['data' => ['id' => 'evt_synthetic_1', 'attributes' => ['type' => 'payment.paid', 'data' => [
            'id' => 'pay_synthetic', 'attributes' => ['amount' => 300000, 'metadata' => ['booking_id' => $victim->id]],
        ]]]];

        $this->postJson('/api/webhooks/paymongo', $payload)->assertStatus(400);
        $this->assertSame('pending_downpayment', $victim->fresh()->status);
    }

    // ---------------------------------------------------------------- AC-04 error disclosure

    /** ACT-040 (regression for audit finding: login reveals whether an e-mail has an account) */
    public function test_login_errors_do_not_reveal_account_existence(): void
    {
        $known = $this->post('/login', ['email' => $this->coachA->email, 'password' => 'wrong-password'])->getSession()->get('error');
        $this->app['auth']->forgetGuards();
        $unknown = $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password'])->getSession()->get('error');
        $this->assertSame($unknown, $known);
    }
}
