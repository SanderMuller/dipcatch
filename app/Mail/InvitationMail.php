<?php declare(strict_types=1);

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to DipCatch',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invitation',
            with: [
                'redeemUrl' => route('invitation.show', ['token' => $this->invitation->token]),
                'expiresAt' => $this->invitation->expires_at,
                'inviterName' => $this->invitation->inviter?->name ?? config('app.name'),
            ],
        );
    }
}
