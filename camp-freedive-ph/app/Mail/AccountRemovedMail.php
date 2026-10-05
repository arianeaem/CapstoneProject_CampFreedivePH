<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountRemovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $removedByName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Camp FreedivePH account has been removed',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account_removed');
    }
}
