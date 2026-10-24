<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\CoachAvailability;
use App\Models\ParticipantAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\ManagementListHelpers;
use Tests\TestCase;

class CoachListExportPrintTest extends TestCase
{
    use ManagementListHelpers;
    use RefreshDatabase;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeStaff();
        $this->coach->update(['email' => 'cora.coach@example.com', 'phone' => '09998887777']);

        $this->booking = Booking::create([
            'booking_number' => 'CFP-COACH-1', 'pin' => '7391', 'contact_name' => 'Guest One', 'contact_email' => 'guest@example.com',
            'contact_phone' => '09175550101', 'class_type' => 'discovery', 'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(), 'status' => 'confirmed', 'total_amount' => 4250, 'balance_amount' => 2250,
        ]);
    }

    private function assign(User $coach, Carbon $date, string $status = 'assigned'): void
    {
        $p = BookingParticipant::create(['booking_id' => $this->booking->id, 'name' => 'Diver ' . uniqid(), 'age' => 30, 'price_per_person' => 4250, 'health_condition' => 'Asthma']);
        ParticipantAssignment::create([
            'participant_id' => $p->id, 'booking_id' => $this->booking->id, 'coach_id' => $coach->id, 'dive_date' => $date->toDateString(),
            'assigned_by' => $this->admin->id, 'assigned_at' => now(), 'status' => $status,
        ]);
    }

    /** Workload for Cora: next dive in 3 days with 2 participants, 1 more in 10 days, plus ones that must not count. */
    private function seedWorkload(): void
    {
        $this->assign($this->coach, now()->addDays(3));
        $this->assign($this->coach, now()->addDays(3));
        $this->assign($this->coach, now()->addDays(10));
        $this->assign($this->coach, now()->subDays(5));                 // past
        $this->assign($this->coach, now()->addDays(1), 'released');     // not active
        CoachAvailability::create(['coach_id' => $this->coach->id, 'date' => now()->addDays(20)->toDateString(), 'status' => 'available']);
        CoachAvailability::create(['coach_id' => $this->coach->id, 'date' => now()->addDays(21)->toDateString(), 'status' => 'available']);
        CoachAvailability::create(['coach_id' => $this->coach->id, 'date' => now()->subDays(2)->toDateString(), 'status' => 'available']); // past
    }

    public function test_export_has_roster_columns_with_staff_contact_details(): void
    {
        $this->seedWorkload();

        $response = $this->actingAs($this->admin)->get('/admin/coaches/export');

        $this->assertXlsxDownload($response, 'coaches');
        [$header, $row] = $this->listSheet($response);
        $this->assertSame(['Name', 'Email', 'Phone', 'Account status', 'Upcoming dive days', 'Next assignment', 'Load on next', 'Open availability dates', 'Participants assigned'], $header);
        $this->assertSame(['Cora Coach', 'cora.coach@example.com', '09998887777', 'Active', '2'], array_slice($row, 0, 5));
        // Next assignment is an Excel date; then load 2, 2 open dates, 4 active assignments (past one included, like the card's list)
        $this->assertSame(['2', '2', '4'], array_slice($row, 6));

        $xml = $this->sheetXml($response, 2);
        foreach (['guest@example.com', '09175550101', '7391', 'Asthma', 'password'] as $secret) {
            $this->assertStringNotContainsString($secret, $xml);
        }
    }

    public function test_roster_cards_export_and_print_show_the_same_workload(): void
    {
        $this->seedWorkload();
        $next = now()->addDays(3)->format('M d, Y');

        // Same figures the roster card always showed
        $this->actingAs($this->owner)->get('/owner/coaches')->assertOk()
            ->assertSee('2 scheduled')->assertSee('2 Open Dates')->assertSee($next)->assertSee('2 / 4 Participants');

        $print = $this->actingAs($this->owner)->get('/owner/coaches/print');
        $print->assertOk()->assertSeeInOrder(['Cora Coach', 'Active', '2', $next, '2 / 4', '2']);
    }

    public function test_roster_page_runs_a_fixed_number_of_queries_for_workload(): void
    {
        foreach (range(1, 6) as $i) {
            $c = User::factory()->create(['role' => 'coach', 'status' => 'active', 'name' => "Coach {$i}"]);
            $this->assign($c, now()->addDays($i));
        }

        DB::enableQueryLog();
        $this->actingAs($this->owner)->get('/owner/coaches')->assertOk();
        $assignmentQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "participant_assignments"'))->count();

        // Eager load + one workload query + the "participants needing a coach" banner, not several per coach
        $this->assertLessThanOrEqual(3, $assignmentQueries);
    }

    public function test_filters_match_the_roster_across_pages(): void
    {
        foreach (range(1, 7) as $i) {
            User::factory()->create(['role' => 'coach', 'status' => $i % 2 ? 'active' : 'inactive', 'name' => "Zed Coach {$i}"]);
        }
        User::factory()->create(['role' => 'coach', 'status' => 'archived', 'name' => 'Archived Coach']);

        $onScreen = collect();
        foreach ([1, 2] as $page) {
            $onScreen = $onScreen->merge(collect($this->actingAs($this->admin)->get("/admin/coaches?search=zed&per_page=5&page={$page}")->viewData('coaches')->items())->pluck('name'));
        }
        $exported = collect($this->listSheet($this->actingAs($this->admin)->get('/admin/coaches/export?search=zed&page=2')))->skip(1)->pluck(0)->values();
        $this->assertCount(7, $onScreen);
        $this->assertSame($onScreen->all(), $exported->all());

        // Archived coaches are hidden unless picked
        $all = collect($this->listSheet($this->actingAs($this->admin)->get('/admin/coaches/export')))->pluck(0);
        $this->assertNotContains('Archived Coach', $all);
        $archived = collect($this->listSheet($this->actingAs($this->admin)->get('/admin/coaches/export?status=archived')))->skip(1)->pluck(0)->all();
        $this->assertSame(['Archived Coach'], $archived);

        $inactive = collect($this->listSheet($this->actingAs($this->admin)->get('/admin/coaches/export?status=inactive')))->skip(1)->pluck(0)->all();
        $this->assertSame(['Zed Coach 2', 'Zed Coach 4', 'Zed Coach 6'], $inactive);
    }

    public function test_search_ignores_letter_case_on_screen_in_excel_and_in_print(): void
    {
        // PostgreSQL LIKE is case-sensitive; staff search must not be (name and email, all three outputs)
        User::factory()->create(['role' => 'coach', 'status' => 'active', 'name' => 'Other Person', 'email' => 'other.person@example.com']);

        foreach (['CORA.COACH@EXAMPLE.COM', 'cOrA'] as $term) {
            $screen = collect($this->actingAs($this->admin)->get('/admin/coaches?search=' . urlencode($term))->viewData('coaches')->items())->pluck('name')->all();
            $excel = collect($this->listSheet($this->actingAs($this->admin)->get('/admin/coaches/export?search=' . urlencode($term))))->skip(1)->pluck(0)->values()->all();

            $this->assertSame(['Cora Coach'], $screen, "Screen search '{$term}'.");
            $this->assertSame(['Cora Coach'], $excel, "Excel search '{$term}'.");
            $this->actingAs($this->admin)->get('/admin/coaches/print?search=' . urlencode($term))->assertOk()
                ->assertSee('Cora Coach')->assertDontSee('Other Person');
        }
    }

    public function test_available_on_and_has_capacity_filters(): void
    {
        $other = User::factory()->create(['role' => 'coach', 'status' => 'active', 'name' => 'Busy Coach']);
        $day = now()->addDays(12)->toDateString();
        CoachAvailability::create(['coach_id' => $this->coach->id, 'date' => $day, 'status' => 'available']);
        foreach (range(1, 4) as $i) {
            $this->assign($other, now());
        }

        $names = fn (string $q) => collect($this->listSheet($this->actingAs($this->admin)->get("/admin/coaches/export?{$q}")))->skip(1)->pluck(0)->values()->all();

        $this->assertSame(['Cora Coach'], $names("available_on={$day}"));
        $this->assertSame(['Cora Coach'], $names('has_capacity=1'));
        $this->assertSame(['Busy Coach', 'Cora Coach'], $names('has_capacity=0'));
    }

    public function test_available_on_matches_the_date_on_screen_in_excel_and_in_print(): void
    {
        $day = now()->addDays(15)->toDateString();
        $assigned = User::factory()->create(['role' => 'coach', 'status' => 'active', 'name' => 'Ava Assigned']);
        $unavailable = User::factory()->create(['role' => 'coach', 'status' => 'active', 'name' => 'Uma Unavailable']);
        $otherDay = User::factory()->create(['role' => 'coach', 'status' => 'active', 'name' => 'Otto Otherday']);
        $inactive = User::factory()->create(['role' => 'coach', 'status' => 'inactive', 'name' => 'Ivy Inactive']);
        User::factory()->create(['role' => 'coach', 'status' => 'active', 'name' => 'Nora Noentry']);
        CoachAvailability::create(['coach_id' => $this->coach->id, 'date' => $day, 'status' => 'available']);
        CoachAvailability::create(['coach_id' => $assigned->id, 'date' => $day, 'status' => 'assigned']);
        CoachAvailability::create(['coach_id' => $unavailable->id, 'date' => $day, 'status' => 'unavailable']);
        CoachAvailability::create(['coach_id' => $otherDay->id, 'date' => now()->addDays(16)->toDateString(), 'status' => 'available']);
        CoachAvailability::create(['coach_id' => $inactive->id, 'date' => $day, 'status' => 'available']);

        $screen = fn (string $q) => collect($this->actingAs($this->admin)->get("/admin/coaches?{$q}")->viewData('coaches')->items())->pluck('name')->all();
        $excel = fn (string $q) => collect($this->listSheet($this->actingAs($this->admin)->get("/admin/coaches/export?{$q}")))->skip(1)->pluck(0)->values()->all();

        // Available or assigned that day; not "unavailable", another day, or no entry. Default status filter still applies (inactive shown, archived hidden).
        $expected = ['Ava Assigned', 'Cora Coach', 'Ivy Inactive'];
        $this->assertSame($expected, $screen("available_on={$day}"));
        $this->assertSame($expected, $excel("available_on={$day}"));
        $this->actingAs($this->admin)->get("/admin/coaches/print?available_on={$day}")->assertOk()
            ->assertSeeInOrder($expected)->assertSee('3 matching records')->assertSee("Available on:</strong> {$day}", false)
            ->assertDontSee('Uma Unavailable')->assertDontSee('Otto Otherday')->assertDontSee('Nora Noentry');

        // Combined with the other filters
        $this->assertSame(['Ava Assigned', 'Cora Coach'], $excel("available_on={$day}&status=active"));
        $this->assertSame(['Ivy Inactive'], $screen("available_on={$day}&status=inactive"));
        $this->assertSame(['Ava Assigned'], $excel("available_on={$day}&search=ava"));
        $this->assertSame([], $excel('available_on=' . now()->addDays(40)->toDateString()));

        // Invalid dates are still refused
        foreach (['/admin/coaches', '/admin/coaches/export', '/admin/coaches/print'] as $path) {
            $this->actingAs($this->admin)->get("{$path}?available_on=24/10/2026")->assertStatus(422);
        }
    }

    public function test_invalid_available_on_is_a_422_not_a_500(): void
    {
        foreach (['available_on=not-a-date', 'available_on=2026-02-30', 'available_on[]=2026-01-01', 'status=deleted', 'status[]=active', 'has_capacity=maybe', 'search[]=x'] as $query) {
            foreach (['/admin/coaches', '/admin/coaches/export', '/admin/coaches/print'] as $path) {
                $this->actingAs($this->admin)->get("{$path}?{$query}")->assertStatus(422);
            }
        }
    }

    public function test_print_roster_is_portrait_without_contact_details(): void
    {
        foreach (['owner' => $this->owner, 'admin' => $this->admin] as $prefix => $user) {
            $response = $this->actingAs($user)->get("/{$prefix}/coaches/print");
            $response->assertOk();
            $response->assertSee('Instructor roster');
            $response->assertSee('size: A4 portrait', false);
            $response->assertSeeInOrder(['Name', 'Account status', 'Upcoming dive days', 'Next assignment', 'Load', 'Open dates']);
            $response->assertSee('Cora Coach');
            foreach (['cora.coach@example.com', '09998887777', $this->admin->email, $this->owner->email] as $secret) {
                $response->assertDontSee($secret, false);
            }
        }
    }

    public function test_empty_result_and_print_limit(): void
    {
        $this->actingAs($this->admin)->get('/admin/coaches/print?search=nobody')->assertOk()->assertSee('No records match these filters.');

        User::factory()->count(200)->create(['role' => 'coach', 'status' => 'active']);
        $this->actingAs($this->admin)->get('/admin/coaches/print')->assertStatus(422)->assertSee('201 records match');
        $this->assertCount(202, $this->listSheet($this->actingAs($this->admin)->get('/admin/coaches/export')));
    }

    public function test_export_is_audit_logged_without_search_text(): void
    {
        $this->actingAs($this->admin)->get('/admin/coaches/export?search=cora.coach@example.com&status=active')->assertOk();

        $log = AuditLog::where('action', 'COACHES_EXPORTED')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertStringContainsString('(1 records)', $log->description);
        $this->assertStringContainsString('search=(used), status=active', $log->description);
        $this->assertStringNotContainsString('cora.coach', $log->description);
    }

    public function test_access_routes_and_list_buttons(): void
    {
        foreach (['export', 'print'] as $action) {
            $this->actingAs($this->coach)->get("/owner/coaches/{$action}")->assertForbidden();
            $this->actingAs($this->coach)->get("/admin/coaches/{$action}")->assertForbidden();
        }
        // /coaches/export must not be read as a coach id
        $this->assertSame('owner.coaches.export', app('router')->getRoutes()->match(request()->create('/owner/coaches/export'))->getName());

        $this->actingAs($this->owner)->get('/owner/coaches?status=inactive&page=2')
            ->assertSee(e(route('owner.coaches.export', ['status' => 'inactive'])), false)
            ->assertSee('Print Instructor Roster');

        auth()->logout();
        $this->get('/owner/coaches/print')->assertRedirect('/login');
    }
}
