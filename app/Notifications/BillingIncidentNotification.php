<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\StripeDispute;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Money trouble the owner must see: a refund, or a chargeback opening or
 * closing. Goes to admins only, by mail and to the admin panel.
 */
final class BillingIncidentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private function __construct(
        public readonly string $kind,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?int $userId = null,
    ) {
        $this->afterCommit();
    }

    public static function disputeOpened(StripeDispute $dispute): self
    {
        return new self(
            kind: 'dispute_opened',
            subject: 'Chargeback opened: ' . self::money($dispute->amount, $dispute->currency),
            body: self::who($dispute) . ' opened a chargeback for '
                . self::money($dispute->amount, $dispute->currency)
                . ($dispute->reason === null || $dispute->reason === '' ? '' : ' (' . $dispute->reason . ')')
                . '. Pro access stays until the dispute is lost.',
            userId: $dispute->user_id,
        );
    }

    public static function disputeClosed(StripeDispute $dispute): self
    {
        $lost = $dispute->isLost();

        return new self(
            kind: $lost ? 'dispute_lost' : 'dispute_closed',
            subject: 'Chargeback ' . $dispute->status . ': ' . self::money($dispute->amount, $dispute->currency),
            body: self::who($dispute) . ' — chargeback closed as ' . $dispute->status . '. '
                . ($lost
                    ? 'Pro access is revoked. Tracked products are untouched.'
                    : 'Pro access is restored.'),
            userId: $dispute->user_id,
        );
    }

    public static function refund(int $amount, string $currency, ?User $user): self
    {
        return new self(
            kind: 'refund',
            subject: 'Refund issued: ' . self::money($amount, $currency),
            body: self::email($user) . ' was refunded '
                . self::money($amount, $currency)
                . '. The subscription is unchanged — cancel it in Stripe if that is the intent.',
            userId: $user?->id,
        );
    }

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject($this->subject)
            ->line($this->body);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->subject,
            'body' => $this->body,
            'user_id' => $this->userId,
        ];
    }

    private static function who(StripeDispute $dispute): string
    {
        $email = $dispute->user?->email;

        return is_string($email) && $email !== '' ? $email : 'An unlinked customer';
    }

    private static function email(?User $user): string
    {
        $email = $user?->email;

        return is_string($email) && $email !== '' ? $email : 'An unlinked customer';
    }

    private static function money(int $amount, string $currency): string
    {
        return MoneyFormatter::formatMinor($amount, $currency);
    }
}
