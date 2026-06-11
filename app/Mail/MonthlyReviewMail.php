<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// No ShouldQueue — sent synchronously inside the monthly-review command.
class MonthlyReviewMail extends Mailable
{
    public function __construct(
        public readonly array  $stats,
        public readonly string $periodLabel,
        public readonly string $recipientName,
        public readonly string $reviewUrl,
        public readonly string $settingsUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Dein Rückblick: {$this->periodLabel} 🏃");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.monthly-review');
    }

    public function attachments(): array
    {
        return [];
    }
}
