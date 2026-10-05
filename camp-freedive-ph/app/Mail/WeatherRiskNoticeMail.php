<?php

namespace App\Mail;

use App\Models\Batch;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to each guest when their batch is rated High or Critical Risk less than 18 hours before the dive.
 * Critical: they may reschedule for free or cancel with a full downpayment refund.
 * High: informational only, the dive is still planned.
 */
class WeatherRiskNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public Batch $batch,
        public string $level, // 'critical' | 'high'
        public int $hoursUntilDive,
    ) {}

    public function envelope(): Envelope
    {
        $date = $this->batch->start_date->format('M d');

        return new Envelope(
            subject: $this->level === 'critical'
                ? "Important: unsafe sea conditions for your {$date} dive - you can reschedule or cancel"
                : "Weather update for your {$date} dive: rough conditions expected",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.weather_risk_notice',
            with: [
                'manageUrl' => route('manage.show', ['booking_number' => $this->booking->booking_number, 'pin' => $this->booking->pin]),
            ],
        );
    }
}
