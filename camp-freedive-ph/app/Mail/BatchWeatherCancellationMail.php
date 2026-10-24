<?php

namespace App\Mail;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Services\Refunds\RefundLedger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email to the participant when the camp cancels their batch. Sent through the queue.
 *
 * It states the guest's options under the client-confirmed rules and never says a refund was started or sent
 * (refunds happen only after the guest chooses and staff approve the amount):
 *  - weather before diving: free move, or the downpayment back; anything paid beyond it is reviewed with them;
 *  - camp operational reason: free move, or everything paid back;
 *  - reason not yet classified: our team contacts them with their options.
 * Amounts come from the actual receipts (online and manual), never from one payment status.
 */
class BatchWeatherCancellationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public Booking $booking,
        public Batch $batch,
        public string $cancellationReason,
        public string $reasonCategory = BookingRefund::REASON_WEATHER_PRE_DIVE,
    ) {}

    public function envelope(): Envelope
    {
        $date = $this->booking->start_date ? $this->booking->start_date->format('M d') : 'upcoming';
        $subject = $this->reasonCategory === BookingRefund::REASON_WEATHER_PRE_DIVE
            ? "Your {$date} dive has been cancelled for your safety - Booking #{$this->booking->booking_number}"
            : "Your {$date} dive has been cancelled - Booking #{$this->booking->booking_number}";

        return new Envelope(
            from: new Address(config('mail.from.address', 'gustoariane@gmail.com'), config('mail.from.name', 'Camp FreedivePH')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.batch_weather_cancellation',
            with: $this->amounts() + ['optionsText' => $this->optionsText()],
        );
    }

    /** @return array{totalPaid: float, downpaymentOffered: float} in pesos */
    public function amounts(): array
    {
        $paid = app(RefundLedger::class)->position($this->booking)['paid'] / 100;

        return ['totalPaid' => $paid, 'downpaymentOffered' => min($paid, (float) $this->booking->downpayment_amount)];
    }

    /** The guest's options in plain text (also stored in the notification log). */
    public function optionsText(): string
    {
        ['totalPaid' => $paid, 'downpaymentOffered' => $downpayment] = $this->amounts();
        $peso = fn (float $v) => 'PHP ' . number_format($v, 2);

        if ($paid <= 0) {
            return 'We have no payment on record for this booking, so there is nothing to refund and nothing for you to pay. If you did pay, please reply so we can check.';
        }

        return match ($this->reasonCategory) {
            BookingRefund::REASON_WEATHER_PRE_DIVE => "You can choose: move to another available date for free, or get your downpayment ({$peso($downpayment)}) back."
                . ($paid > $downpayment ? " You have paid {$peso($paid)} in total; our team will review the amount above the downpayment with you." : '')
                . ' Please reply to tell us your choice. No refund has been sent yet.',
            BookingRefund::REASON_CAMP_OPERATIONAL => "You can choose: move to another available date for free, or get back everything you paid ({$peso($paid)}). Please reply to tell us your choice. No refund has been sent yet.",
            default => "Our team will contact you about your options for the {$peso($paid)} you paid. No refund has been sent yet.",
        };
    }

    public function attachments(): array
    {
        return [];
    }
}
