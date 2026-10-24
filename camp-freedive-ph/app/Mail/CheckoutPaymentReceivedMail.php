<?php

namespace App\Mail;

use App\Models\CheckoutIntent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Paid checkout that could not become a booking (the date filled up first): the payment is acknowledged, no booking
 * is claimed, and no booking number or PIN exists yet. Staff decide the next step.
 */
class CheckoutPaymentReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CheckoutIntent $intent
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment received, booking under review: ' . $this->intent->reference . ' | Camp FreedivePH',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.checkout_payment_received',
            with: ['contactName' => $this->intent->payload['contact']['name'] ?? 'there'],
        );
    }
}
