<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\CancellationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CancellationRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public CancellationRequest $cancellationRequest
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Cancellation Request Received: ' . $this->booking->booking_number . ' | Camp FreedivePH',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.cancellation_requested',
        );
    }
}
