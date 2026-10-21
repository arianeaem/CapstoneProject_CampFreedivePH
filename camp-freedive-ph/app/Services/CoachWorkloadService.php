<?php

namespace App\Services;

use App\Models\ParticipantAssignment;
use Illuminate\Support\Collection;

/**
 * Workload figures shown on the coach roster cards (and its Excel/print versions).
 * Same rules as before, but one query for all coaches instead of several per coach.
 */
class CoachWorkloadService
{
    /**
     * @param Collection $coaches coaches with coachAvailabilities loaded
     * @return array<int, array{upcoming_days: int, next_dive_date: ?\Carbon\Carbon, load_on_next: int, open_dates: int}> keyed by coach id
     */
    public function forCoaches(Collection $coaches): array
    {
        $today = now()->toDateString();

        // Active assignments from today on
        $upcoming = ParticipantAssignment::whereIn('coach_id', $coaches->pluck('id'))
            ->where('status', 'assigned')
            ->where('dive_date', '>=', $today)
            ->get(['coach_id', 'dive_date'])
            ->groupBy('coach_id');

        return $coaches->mapWithKeys(function ($coach) use ($upcoming, $today) {
            $mine = $upcoming->get($coach->id, collect());
            $days = $mine->map(fn ($a) => $a->dive_date->format('Y-m-d'));
            $next = $days->min();

            return [$coach->id => [
                'upcoming_days' => $days->unique()->count(),
                'next_dive_date' => $next ? $mine->first(fn ($a) => $a->dive_date->format('Y-m-d') === $next)->dive_date : null,
                // Participants on that next dive day
                'load_on_next' => $next ? $days->filter(fn ($d) => $d === $next)->count() : 0,
                'open_dates' => $coach->coachAvailabilities->where('status', 'available')->where('date', '>=', $today)->count(),
            ]];
        })->all();
    }
}
