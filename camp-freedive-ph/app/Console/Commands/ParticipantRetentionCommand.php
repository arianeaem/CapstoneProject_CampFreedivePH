<?php

namespace App\Console\Commands;

use App\Services\ParticipantDirectoryService;
use Illuminate\Console\Command;

/**
 * Monthly retention for people who don't book again:
 * health notes cleared 12 months after their last dive, records anonymised after 3 years.
 */
class ParticipantRetentionCommand extends Command
{
    protected $signature = 'participants:retention {--backfill : Also link booking participants that have no directory record}';

    protected $description = 'Apply the participant data retention schedule (12 months health notes, 3 years records)';

    public function handle(ParticipantDirectoryService $directory): int
    {
        if ($this->option('backfill')) {
            $linked = $directory->backfill();
            $this->info("Linked {$linked} booking participant(s) to the directory.");
        }

        $result = $directory->applyRetention();
        $this->info("Health notes cleared: {$result['health_cleared']} · Records anonymised: {$result['anonymised']}");

        return self::SUCCESS;
    }
}
