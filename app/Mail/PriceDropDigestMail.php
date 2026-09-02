<?php declare(strict_types=1);

namespace App\Mail;

use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Daily price-drop digest for a single user — one message summarising every
 * PriceDropEvent fired since their previous digest, grouped by product.
 *
 * Takes a flat collection of events (caller filters + orders); groups
 * internally and exposes `$grouped` to the Blade view.
 */
class PriceDropDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Grouped events for the Blade view: `product_id => { product, events }`.
     * `events` widens to `Collection<int, mixed>` because Eloquent's
     * `->values()` doesn't carry the inner generic — view treats them as
     * PriceDropEvent regardless.
     *
     * @var Collection<int|string, array{product: ?Product, events: Collection<int, mixed>}>
     */
    public Collection $grouped;

    public int $totalDrops;

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, PriceDropEvent>  $events
     */
    public function __construct(
        public User $user,
        \Illuminate\Database\Eloquent\Collection $events,
    ) {
        $this->totalDrops = $events->count();
        $this->grouped = $events
            ->groupBy('product_id')
            ->map(function (Collection $eventsForProduct): array {
                $first = $eventsForProduct->first();
                assert($first instanceof PriceDropEvent);

                return [
                    'product' => $first->product,
                    'events' => $eventsForProduct->values(),
                ];
            });
    }

    public function envelope(): Envelope
    {
        $subject = $this->totalDrops === 1
            ? '1 price drop today'
            : "{$this->totalDrops} price drops today";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.price-drop-digest');
    }
}
