<?php

namespace App\Mail;

use App\Models\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CoachScheduleNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Batch $batch,
        public string $eventTitle,
        public string $message,
        public ?string $bookingNumber = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->eventTitle} - {$this->batch->batch_code} | Camp FreedivePH",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.coach_schedule_notification');
    }
}
