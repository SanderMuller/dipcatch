<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The card was declined. Stripe keeps retrying, so this tells the customer
 * what to fix and where, and does not threaten them with data loss —
 * tracked products keep working whatever happens to the subscription.
 */
final class SubscriptionPaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {
        $this->afterCommit();
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
            ->subject(__('Your Dipcatch payment did not go through'))
            ->line(__('The card on file was declined for :amount.', ['amount' => $this->money()]))
            ->line(__('Update your card to keep Pro. Your tracked products keep working either way.'))
            ->action(__('Update payment details'), url('/app/billing'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'kind' => 'payment_failed',
            'title' => __('Payment failed'),
            'body' => __('The card on file was declined for :amount.', ['amount' => $this->money()]),
            'view_url' => url('/app/billing'),
        ];
    }

    private function money(): string
    {
        return MoneyFormatter::formatMinor($this->amount, $this->currency);
    }
}
