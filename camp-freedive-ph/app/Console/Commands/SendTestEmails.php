<?php

namespace App\Console\Commands;

use App\Mail\AdminOperationalNotificationMail;
use App\Mail\BatchRescheduledMail;
use App\Mail\BatchWeatherCancellationMail;
use App\Mail\BookingConfirmedMail;
use App\Mail\CancellationApprovedMail;
use App\Mail\CancellationRejectedMail;
use App\Mail\CancellationRequestedMail;
use App\Mail\CoachScheduleNotificationMail;
use App\Mail\PasswordResetMail;
use App\Mail\RescheduleApprovedMail;
use App\Mail\RescheduleRejectedMail;
use App\Mail\RescheduleRequestedMail;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\RescheduleRequest;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one sample of every system email to a single address so mail delivery
 * (e.g. Brevo SMTP) can be verified end to end. Uses existing records read-only;
 * nothing is created or modified in the database.
 */
class SendTestEmails extends Command
{
    protected $signature = 'mail:test-all {email : Recipient for all test emails}';

    protected $description = 'Send a sample of every system email to one address and report each result';

    public function handle(): int
    {
        $to = $this->argument('email');

        $this->info('Mailer: ' . config('mail.default')
            . ' | Host: ' . config('mail.mailers.smtp.host') . ':' . config('mail.mailers.smtp.port')
            . ' | From: ' . config('mail.from.name') . ' <' . config('mail.from.address') . '>');
        if (config('mail.default') === 'log') {
            $this->warn('MAIL_MAILER=log: emails are written to storage/logs/laravel.log, not delivered.');
        }

        $booking = Booking::with(['participants', 'payments', 'batch'])->latest()->first()
            ?? new Booking(['booking_number' => 'BK-TEST', 'contact_name' => 'Test Diver', 'contact_email' => $to, 'class_type' => 'discovery', 'status' => 'confirmed', 'total_amount' => 4250, 'start_date' => now()->addWeek(), 'end_date' => now()->addWeek()->addDay()]);
        $batch = $booking->batch ?? Batch::latest('start_date')->first()
            ?? new Batch(['batch_code' => 'TEST-BATCH', 'name' => 'Test Batch', 'start_date' => now()->addWeek(), 'end_date' => now()->addWeek()->addDay(), 'status' => 'open']);
        $cancellation = CancellationRequest::latest()->first()
            ?? new CancellationRequest(['booking_id' => $booking->id, 'status' => 'pending', 'reason' => 'Test cancellation']);
        $reschedule = RescheduleRequest::latest()->first()
            ?? new RescheduleRequest(['booking_id' => $booking->id, 'status' => 'pending', 'reason' => 'Test reschedule']);
        $user = User::where('role', 'owner')->first() ?? User::first();

        $emails = [
            'Booking confirmed' => fn () => new BookingConfirmedMail($booking),
            'Reschedule requested' => fn () => new RescheduleRequestedMail($booking, $reschedule),
            'Reschedule approved' => fn () => new RescheduleApprovedMail($booking, $reschedule),
            'Reschedule rejected' => fn () => new RescheduleRejectedMail($booking, $reschedule, 'Test: requested date is fully booked.'),
            'Cancellation requested' => fn () => new CancellationRequestedMail($booking, $cancellation),
            'Cancellation approved' => fn () => new CancellationApprovedMail($booking, $cancellation, 1500.00, false),
            'Cancellation rejected' => fn () => new CancellationRejectedMail($booking, $cancellation, 'Test: outside the cancellation window.'),
            'Batch rescheduled' => fn () => new BatchRescheduledMail($booking, $batch, 'Test: moved for operational reasons.'),
            'Weather cancellation' => fn () => new BatchWeatherCancellationMail($booking, $batch, 'Test: Critical Risk marine conditions.'),
            'Coach schedule notice' => fn () => new CoachScheduleNotificationMail($batch, 'Test schedule update', 'This is a test coach notification.', $booking->booking_number),
            'Admin operational alert' => fn () => new AdminOperationalNotificationMail('TEST: Operational alert', 'This is a test admin notification.', ['Batch' => $batch->batch_code ?? 'N/A'], 'info'),
            'Password reset' => fn () => new PasswordResetMail(url('/reset-password/test-token'), $user),
        ];

        $failed = 0;
        foreach ($emails as $label => $make) {
            try {
                Mail::to($to)->sendNow($make());
                $this->line("  <info>SENT</info>   {$label}");
            } catch (\Throwable $e) {
                $failed++;
                $this->line("  <error>FAILED</error> {$label}: " . $e->getMessage());
            }
        }

        $this->newLine();
        $failed === 0
            ? $this->info('All ' . count($emails) . " emails handed to the mailer for {$to}. Check Inbox, Spam and Promotions.")
            : $this->error("{$failed} of " . count($emails) . ' emails failed (see errors above).');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
