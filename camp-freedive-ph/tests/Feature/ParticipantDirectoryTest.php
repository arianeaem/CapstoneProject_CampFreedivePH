<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Participant;
use App\Models\ParticipantMatchReview;
use App\Models\User;
use App\Services\ParticipantDirectoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function book(array $people, string $start = '+20 days', array $booking = []): Booking
    {
        $startDate = Carbon::parse($start)->startOfDay();
        $b = Booking::create(array_merge([
            'booking_number' => strtoupper('CFP-T-' . uniqid()),
            'pin' => '1234',
            'class_type' => 'discovery',
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDay(),
            'pickup_option' => 'own',
            'subtotal' => 0, 'total_amount' => 0, 'downpayment_amount' => 0, 'balance_amount' => 0,
            'contact_name' => 'Contact', 'contact_email' => 'contact@example.com', 'contact_phone' => '09170000000',
            'status' => 'confirmed',
        ], $booking));

        foreach ($people as [$name, $birthdate]) {
            $b->participants()->create([
                'name' => $name, 'birthdate' => $birthdate, 'age' => Carbon::parse($birthdate)->age,
                'gender' => 'female', 'health_condition' => 'Asthma', 'swimmer_status' => 'swimmer', 'price_per_person' => 4250,
            ]);
        }

        return $b;
    }

    public function test_same_name_and_birthdate_become_one_person_with_history(): void
    {
        $this->book([['Ana  Cruz', '1995-05-05']], '-400 days', ['status' => 'completed']);
        $second = $this->book([['ana cruz', '1995-05-05']]);

        $ids = BookingParticipant::whereIn('name', ['Ana  Cruz', 'ana cruz'])->pluck('participant_id')->unique();
        $this->assertCount(1, $ids);

        $person = Participant::find($ids->first());
        $this->assertSame(2, $person->activeBookingParticipants()->count());
        $this->assertSame($second->start_date->toDateString(), $person->last_dive_date->toDateString());
        $this->assertSame(2, app(ParticipantDirectoryService::class)->diveNumber($second->participants->first()));
    }

    public function test_close_name_with_same_birthdate_is_flagged_not_merged(): void
    {
        $this->book([['Marco Villanueva', '1990-01-01']]);
        $this->book([['Marco Villanueva Jr.', '1990-01-01']]);

        $ids = Participant::listed()->where('name_key', 'like', 'marco villanueva%')->pluck('id');
        $this->assertCount(2, $ids);
        $this->assertSame(1, ParticipantMatchReview::where('status', 'open')
            ->whereIn('participant_a_id', $ids)->whereIn('participant_b_id', $ids)->count());
    }

    public function test_merge_and_unmerge_restore_each_history(): void
    {
        $this->book([['Marco Villanueva', '1990-01-01']]);
        $this->book([['Marco Villanueva Jr.', '1990-01-01']]);
        [$a, $b] = Participant::listed()->where('name_key', 'like', 'marco villanueva%')->orderBy('id')->get()->all();
        $admin = User::where('role', 'admin')->first();
        $service = app(ParticipantDirectoryService::class);

        $service->merge($a, $b, $admin);
        $this->assertSame(2, $a->fresh()->bookingParticipants()->count());
        $this->assertSame($a->id, $b->fresh()->merged_into_id);

        $service->unmerge($b->fresh(), $admin);
        $this->assertSame(1, $a->fresh()->bookingParticipants()->count());
        $this->assertSame(1, $b->fresh()->bookingParticipants()->count());
        $this->assertNull($b->fresh()->merged_into_id);
    }

    public function test_retention_clears_health_after_12_months_and_anonymises_after_3_years(): void
    {
        $this->book([['Old Diver', '1980-02-02']], '-4 years', ['status' => 'completed']);
        $this->book([['Recent Diver', '1981-03-03']], '-14 months', ['status' => 'completed']);
        $this->book([['Current Diver', '1982-04-04']], '-2 months', ['status' => 'completed']);

        $result = app(ParticipantDirectoryService::class)->applyRetention();

        $this->assertSame(1, $result['anonymised']);
        $this->assertSame(0, Participant::where('full_name', 'Old Diver')->count());
        $this->assertSame(0, BookingParticipant::where('name', 'Old Diver')->count());

        $recent = Participant::where('full_name', 'Recent Diver')->first();
        $this->assertNull($recent->latest_health_condition);
        $this->assertNull(BookingParticipant::where('participant_id', $recent->id)->first()->health_condition);

        $this->assertSame('Asthma', Participant::where('full_name', 'Current Diver')->first()->latest_health_condition);
    }

    public function test_admin_and_owner_can_use_the_directory_pages(): void
    {
        $this->book([['Ana Cruz', '1995-05-05']]);
        $person = Participant::where('full_name', 'Ana Cruz')->first();

        foreach (['admin' => 'admin', 'owner' => 'owner'] as $role => $prefix) {
            $user = User::where('role', $role)->first();
            $this->actingAs($user)->get(route("{$prefix}.participants.index"))->assertOk()->assertSee('Ana Cruz');
            $this->actingAs($user)->get(route("{$prefix}.participants.show", $person))->assertOk()->assertSee('Booking history');
            $this->actingAs($user)->get(route("{$prefix}.participants.index", ['tab' => 'duplicates']))->assertOk();
            $this->actingAs($user)->get(route("{$prefix}.participants.index", ['tab' => 'retention']))->assertOk();
        }

        $this->assertDatabaseHas('audit_logs', ['action' => 'PARTICIPANT_PROFILE_VIEWED']);
    }

    public function test_search_endpoint_and_coach_cannot_open_admin_directory(): void
    {
        $this->book([['Ana Cruz', '1995-05-05']]);
        $admin = User::where('role', 'admin')->first();

        $this->actingAs($admin)->getJson(route('admin.participants.search', ['q' => 'ana']))
            ->assertOk()->assertJsonFragment(['full_name' => 'Ana Cruz']);

        $coach = User::where('role', 'coach')->first();
        $this->actingAs($coach)->get(route('admin.participants.index'))->assertForbidden();
    }

    public function test_anonymise_on_request(): void
    {
        $this->book([['Ana Cruz', '1995-05-05']]);
        $person = Participant::where('full_name', 'Ana Cruz')->first();
        $admin = User::where('role', 'admin')->first();

        $this->actingAs($admin)->post(route('admin.participants.anonymise', $person), ['confirm' => '1'])->assertRedirect();

        $this->assertNotNull($person->fresh()->anonymized_at);
        $this->assertSame(0, BookingParticipant::where('name', 'Ana Cruz')->count());
    }

    public function test_coach_sees_history_only_for_their_own_participants(): void
    {
        $this->book([['Lia Santos', '1992-02-02']], '-300 days', ['status' => 'completed']);
        $booking = $this->book([['Lia Santos', '1992-02-02']]);
        $bp = $booking->participants->first();
        $coach = User::where('role', 'coach')->first();
        $otherCoach = User::where('role', 'coach')->where('id', '!=', $coach->id)->first();
        $admin = User::where('role', 'admin')->first();

        \App\Models\ParticipantAssignment::create([
            'participant_id' => $bp->id, 'booking_id' => $booking->id, 'coach_id' => $coach->id,
            'dive_date' => $booking->start_date, 'assigned_by' => $admin->id, 'status' => 'assigned',
        ]);

        $this->actingAs($coach)->get(route('coach.participants.show', $bp->participant_id))
            ->assertOk()->assertSee('Dive history')->assertSee('Lia Santos');
        $this->actingAs($otherCoach)->get(route('coach.participants.show', $bp->participant_id))->assertForbidden();
    }

    public function test_booking_page_shows_which_booking_this_is_for_the_person(): void
    {
        $this->book([['Lia Santos', '1992-02-02']], '-300 days', ['status' => 'completed']);
        $booking = $this->book([['Lia Santos', '1992-02-02']]);
        $admin = User::where('role', 'admin')->first();

        $this->actingAs($admin)->get(route('admin.bookings.show', $booking))->assertOk()->assertSee('2nd booking');
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_book_again_prefills_from_the_participants_own_booking_only(): void
    {
        $booking = $this->book([['Lia Santos', '1992-02-02']]);

        // Without the booking number + PIN session: sent to Manage Booking
        $this->get(route('manage.book_again', $booking->booking_number))->assertRedirect(route('manage.index'));

        $this->withSession(['auth_booking_id' => $booking->id])
            ->get(route('manage.book_again', $booking->booking_number))
            ->assertRedirect(route('booking.create', ['class' => 'discovery']))
            ->assertSessionHas('book_again', fn ($data) => $data['participants'][0]['birthdate'] === '1992-02-02'
                && $data['participants'][0]['last_name'] === 'Santos');
    }

    public function test_directory_toolbar_filters_and_triggered_history_toolbar(): void
    {
        $this->book([['Ana Cruz', '1995-05-05']]);
        $this->book([['Ben Reyes', '2012-01-01']], '+21 days', ['class_type' => 'fundive']);
        $admin = User::where('role', 'admin')->first();

        $this->actingAs($admin)->get(route('admin.participants.index', ['class_type' => 'fundive']))
            ->assertOk()->assertSee('Filter & Sort', false)->assertSee('Ben Reyes')->assertDontSee('Ana Cruz');
        $this->actingAs($admin)->get(route('admin.participants.index', ['age_group' => 'minor', 'sort' => 'name']))
            ->assertOk()->assertSee('Ben Reyes')->assertDontSee('Ana Cruz');

        $rule = \App\Models\PricingRule::first() ?? \App\Models\PricingRule::create([
            'name' => 'Toolbar Test', 'rule_type' => 'seasonality', 'condition_value' => 'peak', 'applies_to' => 'all',
            'adjustment_type' => 'increase', 'adjustment_method' => 'percentage', 'adjustment_value' => 10, 'priority' => 1, 'status' => 'active',
        ]);
        foreach (['booked_desc', 'dive_asc', 'impact_desc'] as $sort) {
            $this->actingAs($admin)->get(route('admin.pricing.triggered', [$rule, 'sort' => $sort, 'class_type' => 'discovery', 'search' => 'CFP']))
                ->assertOk()->assertSee('Filter & Sort', false);
        }
        $owner = User::where('role', 'owner')->first();
        $this->actingAs($owner)->get(route('owner.pricing.triggered', $rule))->assertOk()->assertDontSee('/admin/pricing/', false);
    }

    public function test_coach_schedule_lists_upcoming_and_past_dives_with_roster_details(): void
    {
        $coach = User::where('role', 'coach')->first();
        $admin = User::where('role', 'admin')->first();
        $assign = function (Booking $booking) use ($coach, $admin) {
            $batch = \App\Models\Batch::create([
                'name' => 'B-' . $booking->id, 'batch_code' => 'B-' . $booking->id,
                'start_date' => $booking->start_date, 'end_date' => $booking->end_date, 'status' => 'open', 'lifecycle_status' => 'open',
            ]);
            $booking->update(['batch_id' => $batch->id]);
            foreach ($booking->participants as $p) {
                \App\Models\ParticipantAssignment::create([
                    'participant_id' => $p->id, 'booking_id' => $booking->id, 'coach_id' => $coach->id, 'batch_id' => $batch->id,
                    'dive_date' => $booking->start_date, 'assigned_by' => $admin->id, 'status' => 'assigned',
                ]);
            }
        };

        $assign($this->book([['Kid Diver', now()->subYears(12)->toDateString()]], '+5 days'));
        $assign($this->book([['Past Diver', '1990-01-01']], '-40 days', ['status' => 'completed']));

        $this->actingAs($coach)->get(route('coach.schedule.index'))
            ->assertOk()
            ->assertSee('My Schedule')
            ->assertSee('Kid Diver')
            ->assertSee('In 5 days')
            ->assertSee('Past Diver');
    }
}
