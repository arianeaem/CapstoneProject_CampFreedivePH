<?php

namespace App\Mail;

use App\Models\Batch;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BatchRescheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Booking $booking,
        public Batch $batch,
        public ?string $rescheduleReason = null
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $startDateStr = $this->batch->start_date ? $this->batch->start_date->format('M d, Y') : '';
        $endDateStr = $this->batch->end_date ? $this->batch->end_date->format('M d, Y') : '';
        $dateRange = $startDateStr . ($endDateStr ? " - {$endDateStr}" : '');

        return new Envelope(
            from: new Address(config('mail.from.address', 'gustoariane@gmail.com'), config('mail.from.name', 'Camp FreedivePH')),
            subject: "Camp Schedule Rescheduled ({$dateRange}) - Booking #{$this->booking->booking_number} | Camp FreedivePH",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.batch_rescheduled',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
