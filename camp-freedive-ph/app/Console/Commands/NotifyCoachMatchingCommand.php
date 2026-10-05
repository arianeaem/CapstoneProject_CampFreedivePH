<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Services\AdminNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class NotifyCoachMatchingCommand extends Command
{
    protected $signature = 'coaches:notify-matching';
    protected $description = 'Notify admins about batches starting within 24 hours that still need coach matching';

    public function handle(AdminNotificationService $notifications): int
    {
        $now = Carbon::now();
        $limit = $now->copy()->addHours(24);

        Batch::whereNotIn('status', ['completed', 'cancelled', 'cancelled_by_camp', 'cancelled_by_guest'])
            ->whereBetween('start_date', [$now, $limit])
            ->withCount('activeParticipantAssignments')
            ->get()
            ->each(function (Batch $batch) use ($notifications) {
                $participants = $batch->total_participants_count;
                $assigned = (int) $batch->active_participant_assignments_count;
                if ($participants <= $assigned) {
                    return;
                }

                $key = "admin:coach-matching-alert:{$batch->id}:" . $batch->start_date->format('Y-m-d');
                if (Cache::add($key, true, now()->addDay())) {
                    $notifications->coachMatchingNeeded($batch, $participants, $assigned);
                }
            });

        return self::SUCCESS;
    }
}
