<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// No ShouldQueue — this runs synchronously inside SendNewsletterJob
class NewsletterMail extends Mailable
{

    public function __construct(
        public readonly string $subject,
        public readonly string $htmlContent,
        public readonly string $recipientName,
        public readonly string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.newsletter');
    }

    public function attachments(): array
    {
        return [];
    }
}
