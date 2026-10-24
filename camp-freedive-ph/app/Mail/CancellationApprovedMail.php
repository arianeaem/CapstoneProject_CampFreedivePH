<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\CancellationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CancellationApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * $refundUnderReview: a refund case is open (approved cancellations never send money themselves, so the email
     * never says a refund was sent or queued; the amount is only stated once staff pay it out).
     */
    public function __construct(
        public Booking $booking,
        public CancellationRequest $cancellationRequest,
        public float $refundAmount,
        public bool $isForfeited,
        public bool $refundUnderReview = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Cancellation Request Approved: ' . $this->booking->booking_number . ' | Camp FreedivePH',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.cancellation_approved',
            with: ['totalPaid' => app(\App\Services\Refunds\RefundLedger::class)->position($this->booking)['paid'] / 100],
        );
    }
}
