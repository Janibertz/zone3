<?php

namespace App\Jobs;

use App\Mail\NewsletterMail;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendNewsletterJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(public readonly int $newsletterId) {}

    public function handle(): void
    {
        $newsletter = Newsletter::findOrFail($this->newsletterId);

        if ($newsletter->isSent()) {
            return;
        }

        $recipients = User::where('newsletter_opt_in', true)
            ->whereNotNull('email')
            ->get(['id', 'name', 'email', 'unsubscribe_token']);

        $count = 0;
        foreach ($recipients as $user) {
            // Ensure token exists
            if (! $user->unsubscribe_token) {
                $user->update(['unsubscribe_token' => Str::random(64)]);
            }

            $unsubscribeUrl = route('newsletter.unsubscribe', ['token' => $user->unsubscribe_token]);

            Mail::to($user->email, $user->name)->send(new NewsletterMail(
                subject: $newsletter->subject,
                htmlContent: $newsletter->html_content,
                recipientName: $user->name,
                unsubscribeUrl: $unsubscribeUrl,
            ));

            $count++;
        }

        $newsletter->update([
            'sent_at'    => now(),
            'sent_count' => $count,
        ]);
    }
}
