<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingDetailsUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<int, array{label: string, old: string, new: string}> $changes
     */
    public function __construct(
        public Booking $booking,
        public array $changes
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Booking Details Updated: ' . $this->booking->booking_number . ' | Camp FreedivePH',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking_details_updated',
        );
    }
}
