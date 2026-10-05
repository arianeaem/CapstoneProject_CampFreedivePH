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
        // 6:00 PM the evening before the dive: about 12.5 hours before the 6:30 AM start
        Carbon::setTestNow(Carbon::parse('2026-10-10 18:00', 'Asia/Manila'));

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email' => 'admin@example.com',
        ]);
        $batch = Batch::create([
            'name' => 'Urgent Batch',
            'batch_code' => 'URGENT-01',
            'start_date' => '2026-10-11',
            'end_date' => '2026-10-12',
            'status' => 'confirmed',
            'lifecycle_status' => 'confirmed',
        ]);

        app(AdminNotificationService::class)->imminentCriticalRisk($batch);

        Mail::assertSent(AdminOperationalNotificationMail::class, function ($mail) use ($admin) {
            return $mail->hasTo($admin->email)
                && str_contains($mail->eventTitle, 'URGENT')
                && str_contains($mail->messageBody, 'force-majeure');
        });
    }

    public function test_critical_risk_alert_is_not_sent_when_dive_is_more_than_18_hours_away(): void
    {
        Mail::fake();
        // 9:00 AM the day before the dive: about 21.5 hours before the 6:30 AM start
        Carbon::setTestNow(Carbon::parse('2026-10-10 09:00', 'Asia/Manila'));

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email' => 'admin@example.com',
        ]);
        $batch = Batch::create([
            'name' => 'Future Batch',
            'batch_code' => 'FUTURE-01',
            'start_date' => '2026-10-11',
            'end_date' => '2026-10-12',
            'status' => 'confirmed',
            'lifecycle_status' => 'confirmed',
        ]);

        app(AdminNotificationService::class)->imminentCriticalRisk($batch);

        Mail::assertNotSent(AdminOperationalNotificationMail::class);
    }
}
