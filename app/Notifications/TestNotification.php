<?php declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Self-test notification: dispatched from the user's preferences page so they
 * can verify each enabled channel works end-to-end without waiting for a real
 * price drop. Honours the same toggles as `PriceDropNotification`.
 */
final class TestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        $channels = [];

        if ($notifiable->notify_via_email) {
            $channels[] = 'mail';
        }
        if ($notifiable->notify_via_filament) {
            $channels[] = 'database';
        }
        if ($notifiable->notify_via_push && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail(User $notifiable): MailMessage
    {
        // Note: real price-drop emails are batched into the daily digest at
        // 09:00 in the user's local timezone. This test message exists to
        // confirm DipCatch can reach the inbox.
        return new MailMessage()
            ->subject('DipCatch test email')
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line('This is a test email from DipCatch.')
            ->line('If you can read this, we can reach your inbox. Real price-drop alerts arrive as a daily digest at 09:00 in your local time.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'kind' => 'test',
            'title' => 'DipCatch test notification',
            'body' => 'In-app notifications are working.',
        ];
    }

    public function toWebPush(User $notifiable): WebPushMessage
    {
        return new WebPushMessage()
            ->title('DipCatch test')
            ->body('Web push notifications are working.')
            ->icon('/favicon.svg');
    }
}
