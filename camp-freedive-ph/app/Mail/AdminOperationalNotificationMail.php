<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminOperationalNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $eventTitle,
        public string $message,
        public array $details = [],
        public string $severity = 'info',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[Camp Operations] {$this->eventTitle}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin_operational_notification');
    }
}
