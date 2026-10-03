<?php

namespace App\Mail;

use App\Models\Batch;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued customer cancellation email dispatched when a batch is cancelled
 * due to severe weather, marine hazard conditions, or force majeure events.
 */
class BatchWeatherCancellationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Number of times the queued job may be attempted.
     */
    public int $tries = 3;

    /**
     * Number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Booking $booking,
        public Batch $batch,
        public string $cancellationReason
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $startDateStr = $this->booking->start_date ? $this->booking->start_date->format('M d, Y') : '';
        $endDateStr = $this->booking->end_date ? $this->booking->end_date->format('M d, Y') : '';
        $dateRange = $startDateStr . ($endDateStr ? " - {$endDateStr}" : '');

        return new Envelope(
            from: new Address(config('mail.from.address', 'gustoariane@gmail.com'), config('mail.from.name', 'Camp FreedivePH')),
            subject: "Camp Cancellation Notice ({$dateRange}) - Booking #{$this->booking->booking_number} | Camp FreedivePH",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.batch_weather_cancellation',
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
