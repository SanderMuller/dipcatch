<?php declare(strict_types=1);

namespace App\Mail;

use App\Enums\SupportRequestType;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class SupportRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public SupportRequestType $requestType,
        public string $message,
        public ?string $shopUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->user->email, $this->user->name)],
            subject: $this->requestType->label() . ' · DipCatch',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.support-request',
            with: [
                'requestTypeLabel' => $this->requestType->label(),
            ],
        );
    }
}
