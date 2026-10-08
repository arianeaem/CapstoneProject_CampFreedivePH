<?php

use App\Services\ParticipantDirectoryService;
use Illuminate\Database\Migrations\Migration;

/**
 * Link every existing booking participant to a directory record (exact name + birthdate
 * matches become one person; close matches go to the review queue).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(ParticipantDirectoryService::class)->backfill();
    }

    public function down(): void
    {
        // Links are removed with the columns in the previous migration's down()
    }
};
