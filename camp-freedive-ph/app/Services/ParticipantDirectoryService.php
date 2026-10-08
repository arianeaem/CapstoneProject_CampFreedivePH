<?php

namespace App\Services;

use App\Models\BookingParticipant;
use App\Models\Participant;
use App\Models\ParticipantMatchReview;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Participant Directory: links every booking participant to one record per real person.
 *
 * Matching (see the PRD):
 * - exact: same normalised full name + same birthdate -> linked automatically
 * - possible: same birthdate + close name, or same name + same contact email/phone
 *   -> new record, flagged for an admin to merge or mark "not the same person"
 *
 * Retention for people who don't book again:
 * - health notes cleared 12 months after the last dive
 * - the record anonymised 3 years after the last dive (a new booking resets the clock)
 */
class ParticipantDirectoryService
{
    public const HEALTH_RETENTION_MONTHS = 12;
    public const RECORD_RETENTION_YEARS = 3;
    public const RETENTION_NOTICE_DAYS = 30;
    public const ANONYMISED_NAME = 'Anonymised participant';

    private const SUFFIXES = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'];

    /** "  Juan  DELA Cruz " -> "juan dela cruz" (accents and Ñ kept). */
    public static function nameKey(?string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $name)));
    }

    /** First and last name only, suffix and middle names dropped: "juan dela cruz jr." -> "juan cruz". */
    private static function coreKey(string $nameKey): string
    {
        $parts = array_values(array_filter(explode(' ', $nameKey), fn ($p) => !in_array($p, self::SUFFIXES, true)));
        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }

        return $parts[0] . ' ' . end($parts);
    }

    private static function namesAreClose(string $a, string $b): bool
    {
        return $a === $b
            || self::coreKey($a) === self::coreKey($b)
            || levenshtein($a, $b) <= 1;
    }

    /**
     * Link one booking participant to the directory (called when the row is created).
     */
    public function link(BookingParticipant $bp): Participant
    {
        if ($bp->participant_id && ($existing = Participant::find($bp->participant_id))) {
            return $existing;
        }

        return DB::transaction(function () use ($bp) {
            $bp->loadMissing('booking');
            $key = self::nameKey($bp->name);
            $birthdate = $bp->birthdate?->format('Y-m-d');

            $participant = $birthdate
                ? Participant::listed()->where('name_key', $key)->whereDate('birthdate', $birthdate)->first()
                : null;

            $isNew = !$participant;
            if ($isNew) {
                $participant = Participant::create([
                    'full_name' => trim(preg_replace('/\s+/u', ' ', $bp->name)),
                    'name_key' => $key,
                    'birthdate' => $birthdate,
                    'gender' => $bp->gender,
                ]);
            }

            $bp->forceFill(['participant_id' => $participant->id, 'original_participant_id' => $participant->id])->saveQuietly();
            $this->refresh($participant);

            if ($isNew) {
                $this->flagPossibleMatches($participant, $bp);
            }

            return $participant;
        });
    }

    /** Flag other records that may be the same person. */
    private function flagPossibleMatches(Participant $participant, BookingParticipant $bp): void
    {
        $candidates = collect();
        $email = $bp->booking?->contact_email ? mb_strtolower($bp->booking->contact_email) : null;
        $phone = $bp->booking?->contact_phone ? preg_replace('/\D/', '', $bp->booking->contact_phone) : null;

        if ($participant->birthdate) {
            Participant::listed()->where('id', '!=', $participant->id)
                ->whereDate('birthdate', $participant->birthdate->format('Y-m-d'))
                ->get()
                ->filter(fn ($other) => self::namesAreClose($participant->name_key, $other->name_key))
                ->each(fn ($other) => $candidates->put($other->id, [$other, 'Same birthdate and a close name']));
        }

        Participant::listed()->where('id', '!=', $participant->id)
            ->where('name_key', $participant->name_key)
            ->get()
            ->filter(function ($other) use ($email, $phone) {
                $otherPhone = $other->last_phone ? preg_replace('/\D/', '', $other->last_phone) : null;

                return ($email && $other->last_email && mb_strtolower($other->last_email) === $email)
                    || ($phone && $otherPhone && substr($otherPhone, -10) === substr($phone, -10));
            })
            ->each(fn ($other) => $candidates->put($other->id, $candidates->get($other->id, [$other, 'Same name and same contact email or phone'])));

        foreach ($candidates as [$other, $reason]) {
            [$a, $b] = $participant->id < $other->id ? [$participant->id, $other->id] : [$other->id, $participant->id];
            ParticipantMatchReview::firstOrCreate(
                ['participant_a_id' => $a, 'participant_b_id' => $b],
                ['reason' => $reason, 'status' => 'open']
            );
        }
    }

    /**
     * Update the person's latest details from their newest booking.
     */
    public function refresh(Participant $participant): void
    {
        $rows = $participant->bookingParticipants()->with('booking')->get()
            ->filter(fn ($row) => $row->booking && !in_array($row->booking->status, Participant::CANCELLED_STATUSES, true))
            ->sortByDesc(fn ($row) => [$row->booking->start_date?->timestamp ?? 0, $row->id]);

        $latest = $rows->first() ?? $participant->bookingParticipants()->with('booking')->latest('id')->first();
        if (!$latest) {
            return;
        }

        $participant->update([
            'gender' => $latest->gender ?? $participant->gender,
            'latest_swimmer_status' => $latest->swimmer_status,
            'latest_health_condition' => $latest->health_condition,
            'health_updated_at' => $latest->created_at,
            'last_email' => $latest->booking?->contact_email,
            'last_phone' => $latest->booking?->contact_phone,
            'last_dive_date' => $rows->first()?->booking?->start_date,
        ]);
    }

    /**
     * Merge $other into $keep: all bookings move, $other is archived (not deleted).
     */
    public function merge(Participant $keep, Participant $other, User $by): void
    {
        DB::transaction(function () use ($keep, $other, $by) {
            $other->bookingParticipants()->update(['participant_id' => $keep->id]);
            $other->update(['merged_into_id' => $keep->id]);

            [$a, $b] = $keep->id < $other->id ? [$keep->id, $other->id] : [$other->id, $keep->id];
            ParticipantMatchReview::updateOrCreate(
                ['participant_a_id' => $a, 'participant_b_id' => $b],
                ['reason' => 'Merged by staff', 'status' => 'merged', 'decided_by' => $by->id, 'decided_at' => now()]
            );
            // Other open suggestions for the archived record now point at nothing useful
            ParticipantMatchReview::where('status', 'open')
                ->where(fn ($q) => $q->where('participant_a_id', $other->id)->orWhere('participant_b_id', $other->id))
                ->update(['status' => 'merged', 'decided_by' => $by->id, 'decided_at' => now()]);

            $this->refresh($keep);

            AuditLogger::log('PARTICIPANT_MERGED', "Merged participant #{$other->id} ({$other->full_name}) into #{$keep->id} ({$keep->full_name}).", $by, $by->name);
        });
    }

    /**
     * Undo a merge: the archived record gets back the bookings it started with.
     */
    public function unmerge(Participant $archived, User $by): Participant
    {
        return DB::transaction(function () use ($archived, $by) {
            $keep = $archived->mergedInto;
            BookingParticipant::where('original_participant_id', $archived->id)->update(['participant_id' => $archived->id]);
            $archived->update(['merged_into_id' => null]);

            if ($keep) {
                [$a, $b] = $keep->id < $archived->id ? [$keep->id, $archived->id] : [$archived->id, $keep->id];
                ParticipantMatchReview::where('participant_a_id', $a)->where('participant_b_id', $b)
                    ->update(['status' => 'not_same', 'reason' => 'Merge undone by staff', 'decided_by' => $by->id, 'decided_at' => now()]);
                $this->refresh($keep);
            }
            $this->refresh($archived);

            AuditLogger::log('PARTICIPANT_UNMERGED', "Undid the merge of participant #{$archived->id} ({$archived->full_name})" . ($keep ? " from #{$keep->id}" : '') . '.', $by, $by->name);

            return $archived;
        });
    }

    public function markNotSame(ParticipantMatchReview $review, User $by): void
    {
        $review->update(['status' => 'not_same', 'decided_by' => $by->id, 'decided_at' => now()]);
        AuditLogger::log('PARTICIPANT_NOT_SAME', "Marked participants #{$review->participant_a_id} and #{$review->participant_b_id} as different people.", $by, $by->name);
    }

    /**
     * Clear the person's name, contact and health details everywhere; booking amounts stay for reports.
     */
    public function anonymise(Participant $participant, ?User $by = null, string $reason = 'request'): void
    {
        DB::transaction(function () use ($participant, $by, $reason) {
            $label = $participant->full_name;

            $participant->bookingParticipants()->update([
                'name' => self::ANONYMISED_NAME,
                'birthdate' => null,
                'health_condition' => null,
            ]);

            $participant->update([
                'full_name' => self::ANONYMISED_NAME,
                'name_key' => 'anonymised-' . $participant->id,
                'birthdate' => null,
                'gender' => null,
                'latest_swimmer_status' => null,
                'latest_health_condition' => null,
                'health_updated_at' => null,
                'last_email' => null,
                'last_phone' => null,
                'anonymized_at' => now(),
            ]);

            ParticipantMatchReview::where('status', 'open')
                ->where(fn ($q) => $q->where('participant_a_id', $participant->id)->orWhere('participant_b_id', $participant->id))
                ->update(['status' => 'not_same', 'decided_at' => now()]);

            AuditLogger::log(
                'PARTICIPANT_ANONYMISED',
                "Anonymised participant #{$participant->id} ({$label}) — " . ($reason === 'retention' ? 'retention schedule.' : 'on request.'),
                $by,
                $by?->name ?? 'System'
            );
        });
    }

    /** People whose record will be anonymised within the notice window. */
    public function dueForAnonymisation(?Carbon $today = null)
    {
        $today ??= Carbon::today('Asia/Manila');
        $cutoff = $today->copy()->subYears(self::RECORD_RETENTION_YEARS);

        return Participant::listed()
            ->whereNotNull('last_dive_date')
            ->whereDate('last_dive_date', '<', $cutoff->copy()->addDays(self::RETENTION_NOTICE_DAYS))
            ->orderBy('last_dive_date');
    }

    /**
     * Monthly retention: clear old health notes, anonymise records past the retention period.
     *
     * @return array{health_cleared: int, anonymised: int}
     */
    public function applyRetention(?Carbon $today = null): array
    {
        $today ??= Carbon::today('Asia/Manila');
        $healthCutoff = $today->copy()->subMonths(self::HEALTH_RETENTION_MONTHS);
        $recordCutoff = $today->copy()->subYears(self::RECORD_RETENTION_YEARS);

        $healthCleared = 0;
        Participant::listed()
            ->whereNotNull('last_dive_date')
            ->whereDate('last_dive_date', '<', $healthCutoff)
            ->where(fn ($q) => $q->whereNotNull('latest_health_condition')->orWhereNotNull('latest_swimmer_status'))
            ->each(function (Participant $p) use (&$healthCleared) {
                $p->bookingParticipants()->update(['health_condition' => null]);
                $p->update(['latest_health_condition' => null, 'latest_swimmer_status' => null, 'health_updated_at' => null]);
                $healthCleared++;
            });

        $anonymised = 0;
        Participant::listed()
            ->whereNotNull('last_dive_date')
            ->whereDate('last_dive_date', '<', $recordCutoff)
            ->each(function (Participant $p) use (&$anonymised) {
                $this->anonymise($p, null, 'retention');
                $anonymised++;
            });

        if ($healthCleared || $anonymised) {
            AuditLogger::log('PARTICIPANT_RETENTION_RUN', "Retention schedule: health notes cleared for {$healthCleared}, records anonymised for {$anonymised}.", null, 'System');
        }

        return ['health_cleared' => $healthCleared, 'anonymised' => $anonymised];
    }

    /** Link every booking participant that has no directory record yet (used once at launch). */
    public function backfill(): int
    {
        $count = 0;
        BookingParticipant::whereNull('participant_id')->with('booking')->orderBy('id')->each(function (BookingParticipant $bp) use (&$count) {
            $this->link($bp);
            $count++;
        });

        return $count;
    }

    /**
     * This booking is the person's Nth (by trip date, cancelled bookings not counted).
     */
    public function diveNumber(BookingParticipant $bp): ?int
    {
        if (!$bp->participant_id || !$bp->booking) {
            return null;
        }

        $start = $bp->booking->start_date;

        return BookingParticipant::where('participant_id', $bp->participant_id)
            ->whereHas('booking', fn ($q) => $q->whereNotIn('status', Participant::CANCELLED_STATUSES)
                ->where(fn ($q) => $q->whereDate('start_date', '<', $start)
                    ->orWhere(fn ($q) => $q->whereDate('start_date', $start)->where('booking_participants.id', '<=', $bp->id))))
            ->count() ?: 1;
    }
}
