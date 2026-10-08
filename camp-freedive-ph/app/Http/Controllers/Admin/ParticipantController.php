<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Participant;
use App\Models\ParticipantMatchReview;
use App\Services\AuditLogger;
use App\Services\ParticipantDirectoryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Participant Directory (owner and admin): every person who has booked, with their history.
 */
class ParticipantController extends Controller
{
    public function __construct(protected ParticipantDirectoryService $directory) {}

    /** The directory query with the page's search, filters and sort applied. */
    private function filtered(Request $request)
    {
        $query = Participant::listed()
            ->withCount(['activeBookingParticipants as bookings_count'])
            ->with(['bookingParticipants.booking:id,class_type,status,start_date']);

        if ($request->filled('search')) {
            $term = mb_strtolower(trim($request->input('search')));
            $digits = preg_replace('/\D/', '', $term);
            $query->where(function ($q) use ($term, $digits) {
                $q->where('name_key', 'like', "%{$term}%")
                  ->orWhereRaw('lower(last_email) like ?', ["%{$term}%"])
                  ->orWhere('last_phone', 'like', "%{$term}%");
                if (strlen($digits) >= 4) {
                    $q->orWhere('last_phone', 'like', "%{$digits}%");
                }
            });
        }

        if ($request->filled('class_type')) {
            $class = $request->input('class_type');
            $query->whereHas('activeBookingParticipants.booking', fn ($q) => $q->where('class_type', $class));
        }

        if ($request->input('history') === 'repeat') {
            $query->has('activeBookingParticipants', '>=', 2);
        } elseif ($request->input('history') === 'first') {
            $query->has('activeBookingParticipants', '<=', 1);
        }

        $adultCutoff = Carbon::today('Asia/Manila')->subYears(18)->toDateString();
        if ($request->input('age_group') === 'minor') {
            $query->whereDate('birthdate', '>', $adultCutoff);
        } elseif ($request->input('age_group') === 'adult') {
            $query->whereDate('birthdate', '<=', $adultCutoff);
        }

        if ($request->filled('from')) {
            $query->whereDate('last_dive_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('last_dive_date', '<=', $request->input('to'));
        }

        return match ($request->input('sort')) {
            'name' => $query->orderBy('name_key'),
            'dives' => $query->orderByDesc('bookings_count')->orderBy('name_key'),
            default => $query->orderByRaw('last_dive_date is null')->orderByDesc('last_dive_date')->orderBy('name_key'),
        };
    }

    public function index(Request $request): View
    {
        $tab = $request->input('tab', 'all');
        $participants = $tab === 'all' ? $this->filtered($request)->paginate(25)->withQueryString() : null;

        $reviews = ParticipantMatchReview::with(['participantA', 'participantB'])
            ->where('status', 'open')
            ->whereHas('participantA', fn ($q) => $q->listed())
            ->whereHas('participantB', fn ($q) => $q->listed())
            ->latest()
            ->get();

        $dueForAnonymisation = $this->directory->dueForAnonymisation()->get();

        $stats = [
            'total' => Participant::listed()->count(),
            'repeat' => Participant::listed()->has('activeBookingParticipants', '>=', 2)->count(),
            'minors' => Participant::listed()->whereDate('birthdate', '>', Carbon::today('Asia/Manila')->subYears(18))->count(),
        ];

        return view('admin.participants.index', compact('tab', 'participants', 'reviews', 'dueForAnonymisation', 'stats'));
    }

    public function show(Request $request, Participant $participant): View|RedirectResponse
    {
        if ($participant->merged_into_id) {
            return redirect()->to(portal_route('participants.show', $participant->merged_into_id))
                ->with('info', "This record was merged into {$participant->mergedInto?->full_name}.");
        }

        $history = $participant->bookingParticipants()
            ->with(['booking.batch', 'booking.payments', 'assignments.coach'])
            ->get()
            ->sortByDesc(fn ($row) => $row->booking?->start_date?->timestamp ?? 0)
            ->values();

        $guardianBooking = $participant->isMinor()
            ? $history->pluck('booking')->filter(fn ($b) => $b?->guardian_consent_at)->first()
            : null;

        $mergedRecords = Participant::where('merged_into_id', $participant->id)->get();

        // Health notes are sensitive: every view of a profile is logged
        AuditLogger::log('PARTICIPANT_PROFILE_VIEWED', "Viewed participant #{$participant->id} ({$participant->full_name}).", $request->user(), $request->user()->name, $request);

        return view('admin.participants.show', compact('participant', 'history', 'guardianBooking', 'mergedRecords'));
    }

    public function update(Request $request, Participant $participant): RedirectResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:255', 'regex:/^(?=.*[\p{L}])[\p{L}\s\.\'\-]+$/u'],
            'birthdate' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'non_binary', 'prefer_not_to_say'])],
            'latest_swimmer_status' => ['nullable', 'string', 'max:32'],
            'latest_health_condition' => ['nullable', 'string', 'max:1000'],
        ]);

        $before = $participant->only(array_keys($validated));
        $participant->update($validated + ['name_key' => ParticipantDirectoryService::nameKey($validated['full_name'])]);

        $changed = collect($validated)->filter(fn ($v, $k) => (string) ($before[$k] instanceof \DateTimeInterface ? $before[$k]->format('Y-m-d') : $before[$k]) !== (string) $v)->keys();
        AuditLogger::log('PARTICIPANT_UPDATED', "Updated participant #{$participant->id} ({$participant->full_name}): " . ($changed->implode(', ') ?: 'no changes') . '.', $request->user(), $request->user()->name, $request);

        return back()->with('success', 'Participant details updated.');
    }

    public function merge(Request $request, Participant $participant): RedirectResponse
    {
        $validated = $request->validate(['other_id' => ['required', 'integer', Rule::exists('participants', 'id')]]);
        $other = Participant::listed()->findOrFail($validated['other_id']);
        abort_if($other->id === $participant->id, 422);

        $this->directory->merge($participant, $other, $request->user());

        return redirect()->to(portal_route('participants.show', $participant))->with('success', "Merged {$other->full_name} into this record. All bookings now show in one history.");
    }

    public function unmerge(Request $request, Participant $participant): RedirectResponse
    {
        abort_unless($participant->merged_into_id, 404);
        $this->directory->unmerge($participant, $request->user());

        return redirect()->to(portal_route('participants.show', $participant))->with('success', 'Merge undone. This record has its own history again.');
    }

    public function notSame(Request $request, ParticipantMatchReview $review): RedirectResponse
    {
        $this->directory->markNotSame($review, $request->user());

        return back()->with('success', 'Marked as different people. This pair won\'t be suggested again.');
    }

    public function anonymise(Request $request, Participant $participant): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted'], ['confirm.accepted' => 'Please confirm that this cannot be undone.']);
        $this->directory->anonymise($participant, $request->user());

        return redirect()->to(portal_route('participants.index'))->with('success', 'Participant anonymised. Their bookings stay in reports without personal details.');
    }

    /** For the admin booking form: find a past participant by name. */
    public function search(Request $request): JsonResponse
    {
        $term = ParticipantDirectoryService::nameKey($request->input('q'));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $results = Participant::listed()->where('name_key', 'like', "%{$term}%")
            ->withCount(['activeBookingParticipants as bookings_count'])
            ->orderByDesc('last_dive_date')->limit(8)->get()
            ->map(fn (Participant $p) => [
                'id' => $p->id,
                'full_name' => $p->full_name,
                'birthdate' => $p->birthdate?->format('Y-m-d'),
                'age' => $p->age,
                'gender' => $p->gender,
                'swimmer_status' => $p->latest_swimmer_status,
                'health_condition' => $p->latest_health_condition,
                'bookings_count' => $p->bookings_count,
                'last_dive' => $p->last_dive_date?->format('M d, Y'),
            ]);

        return response()->json($results);
    }

    public function export(Request $request): StreamedResponse
    {
        AuditLogger::log('PARTICIPANTS_EXPORTED', 'Exported the participant directory.', $request->user(), $request->user()->name, $request);
        $rows = $this->filtered($request)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 (Ñ) correctly with a BOM
            fputcsv($out, ['Name', 'Birthdate', 'Age', 'Gender', 'Swimmer status', 'Bookings', 'Classes taken', 'Last dive', 'Last contact email', 'Last contact phone']);
            foreach ($rows as $p) {
                $classes = $p->bookingParticipants->pluck('booking')->filter()
                    ->reject(fn ($b) => in_array($b->status, Participant::CANCELLED_STATUSES, true))
                    ->pluck('class_type')->unique()->map(fn ($c) => ucfirst($c))->implode(', ');
                fputcsv($out, [
                    $p->full_name, $p->birthdate?->format('Y-m-d'), $p->age, $p->gender, $p->latest_swimmer_status,
                    $p->bookings_count, $classes, $p->last_dive_date?->format('Y-m-d'), $p->last_email, $p->last_phone,
                ]);
            }
            fclose($out);
        }, 'participants-' . now('Asia/Manila')->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
