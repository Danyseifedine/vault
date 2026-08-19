<?php

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the account on every successful sign-in (after two-factor), so a
 * sign-in the owner did not make is noticed the moment it happens rather than
 * whenever they next read the audit log.
 *
 * The request details - where from, on what, when - are captured by the
 * listener at login time and passed in, because a queued mailable runs later,
 * in a worker, with no request of its own.
 */
class NewLoginMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $ip,
        public string $device,
        public string $userAgent,
        public CarbonInterface $at,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New sign-in to '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.new-login',
            with: [
                'name' => $this->user->displayName(),
                'ip' => $this->ip,
                'device' => $this->device,
                'userAgent' => $this->userAgent,
                'at' => $this->at,
                'securityUrl' => route('security.edit'),
            ],
        );
    }
}
