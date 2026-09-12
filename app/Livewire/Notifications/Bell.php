<?php declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The in-app notification bell.
 *
 * Built rather than ported: Filament's panel bell lists only rows whose payload
 * carries `data->format = 'filament'`, and this app writes none, so the bell has
 * never displayed a price drop despite the settings page offering it. See the
 * spec's Findings.
 *
 * Payloads differ per notification type — a price drop carries `view_url`,
 * `new_price` and `host`, a billing incident carries `kind` and `body` and no
 * link at all — so this renders the common denominator and treats every other
 * key as optional.
 */
class Bell extends Component
{
    /** How many rows the dropdown shows. Older ones live on the notifications page. */
    private const int LIMIT = 10;

    public function markAsRead(string $id): void
    {
        $this->notificationQuery()
            ->where('id', $id)
            ->get()
            ->each(fn (DatabaseNotification $notification) => $notification->markAsRead());
    }

    public function markAllAsRead(): void
    {
        $this->user()?->unreadNotifications()->update(['read_at' => now()]);
    }

    public function render(): View
    {
        return view('livewire.notifications.bell', [
            'unreadCount' => $this->unreadCount(),
            'items' => $this->items(),
        ]);
    }

    private function unreadCount(): int
    {
        return $this->user()?->unreadNotifications()->count() ?? 0;
    }

    /**
     * @return Collection<int, non-empty-array<string, mixed>>
     */
    private function items(): Collection
    {
        return $this->notificationQuery()
            ->latest()
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'unread' => $notification->read_at === null,
                'at' => $notification->created_at,
                ...$this->present($notification),
            ]);
    }

    /**
     * The fields the dropdown renders, defaulting anything a given
     * notification type does not carry.
     *
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        return [
            'title' => $this->text($data, 'title') ?? 'Notification',
            'body' => $this->text($data, 'body'),
            'url' => $this->text($data, 'view_url'),
            'host' => $this->text($data, 'host'),
            'price' => $this->text($data, 'new_price'),
            'currency' => $this->text($data, 'currency'),
            'singleItemPrice' => $this->text($data, 'single_item_price'),
            'bundleQuantity' => is_int($data['bundle_quantity'] ?? null) ? $data['bundle_quantity'] : null,
            'bundleTotalPrice' => $this->text($data, 'bundle_total_price'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * @return MorphMany<DatabaseNotification, User>
     */
    private function notificationQuery(): MorphMany
    {
        $user = $this->user();

        abort_if($user === null, 403);

        return $user->notifications();
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
