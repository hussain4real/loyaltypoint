<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ConsumerContactCodeMail extends Mailable
{
    public function __construct(public readonly string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your Pull2Spend email');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.consumer-contact-code');
    }
}
