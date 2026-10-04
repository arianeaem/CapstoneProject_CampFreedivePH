<?php

namespace Tests\Feature;

use App\Mail\AdminOperationalNotificationMail;
use App\Models\Batch;
use App\Models\User;
use App\Services\AdminNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminOperationalNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_imminent_critical_risk_sends_force_majeure_decision_alert(): void
    {
        Mail::fake();

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email' => 'admin@example.com',
        ]);
        $batch = Batch::create([
            'name' => 'Urgent Batch',
            'batch_code' => 'URGENT-01',
            'start_date' => Carbon::now('Asia/Manila')->toDateString(),
            'end_date' => Carbon::now('Asia/Manila')->addDay()->toDateString(),
            'status' => 'confirmed',
            'lifecycle_status' => 'confirmed',
        ]);

        app(AdminNotificationService::class)->imminentCriticalRisk($batch);

        Mail::assertSent(AdminOperationalNotificationMail::class, function ($mail) use ($admin) {
            return $mail->hasTo($admin->email)
                && str_contains($mail->eventTitle, 'URGENT')
                && str_contains($mail->message, 'force-majeure');
        });
    }

    public function test_critical_risk_alert_is_not_sent_when_dive_is_more_than_18_hours_away(): void
    {
        Mail::fake();

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email' => 'admin@example.com',
        ]);
        $batch = Batch::create([
            'name' => 'Future Batch',
            'batch_code' => 'FUTURE-01',
            'start_date' => Carbon::now('Asia/Manila')->addDay()->toDateString(),
            'end_date' => Carbon::now('Asia/Manila')->addDays(2)->toDateString(),
            'status' => 'confirmed',
            'lifecycle_status' => 'confirmed',
        ]);

        app(AdminNotificationService::class)->imminentCriticalRisk($batch);

        Mail::assertNotSent(AdminOperationalNotificationMail::class);
    }
}
