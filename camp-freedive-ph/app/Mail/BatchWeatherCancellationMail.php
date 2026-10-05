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
        $date = $this->booking->start_date ? $this->booking->start_date->format('M d') : 'upcoming';

        return new Envelope(
            from: new Address(config('mail.from.address', 'gustoariane@gmail.com'), config('mail.from.name', 'Camp FreedivePH')),
            subject: "Your {$date} dive has been cancelled for your safety - Booking #{$this->booking->booking_number}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.batch_weather_cancellation',
            with: ['refundAmount' => $this->refundAmount()],
        );
    }

    /**
     * Everything the guest has paid (all completed payments are refunded 100%).
     */
    public function refundAmount(): float
    {
        return (float) $this->booking->payments()->where('status', 'completed')->sum('amount');
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
