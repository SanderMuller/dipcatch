<?php declare(strict_types=1);

namespace App\Mail;

use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\TargetPriceEvent;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Daily digest for a single user — every PriceDropEvent and TargetPriceEvent
 * fired since their previous digest, grouped by product.
 *
 * Takes flat collections of events (caller filters + orders); groups
 * internally and exposes `$grouped` to the Blade view.
 */
final class DailyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Grouped events for the Blade view: `product_id => { product, events,
     * reached }`.
     *
     * @var Collection<int|string, array{product: ?Product, events: Collection<int, PriceDropEvent>, reached: Collection<int, TargetPriceEvent>}>
     */
    public Collection $grouped;

    public int $totalDrops;

    public int $totalReached;

    /** The subject, repeated as the mail's own heading. */
    public string $heading;

    /**
     * @param  EloquentCollection<int, PriceDropEvent>  $events
     * @param  EloquentCollection<int, TargetPriceEvent>  $reached
     */
    public function __construct(
        public User $user,
        EloquentCollection $events,
        EloquentCollection $reached = new EloquentCollection(),
    ) {
        $this->totalDrops = $events->count();
        $this->totalReached = $reached->count();

        $dropsByProduct = $events->groupBy('product_id');
        $reachedByProduct = $reached->groupBy('product_id');

        $this->grouped = $dropsByProduct->keys()
            ->merge($reachedByProduct->keys())
            ->unique()
            ->mapWithKeys(function (int|string $productId) use ($dropsByProduct, $reachedByProduct): array {
                $drops = collect(array_values($dropsByProduct->get($productId)?->all() ?? []));
                $targets = collect(array_values($reachedByProduct->get($productId)?->all() ?? []));
                $first = $targets->first() ?? $drops->first();
                assert($first instanceof PriceDropEvent || $first instanceof TargetPriceEvent);

                return [$productId => [
                    'product' => $first->product,
                    'events' => $drops,
                    'reached' => $targets,
                ]];
            });

        $this->heading = $this->headingFor();
    }

    private function headingFor(): string
    {
        if ($this->totalReached === 0) {
            return $this->totalDrops === 1 ? '1 price drop today' : "{$this->totalDrops} price drops today";
        }

        $total = $this->totalDrops + $this->totalReached;

        return $total === 1 ? '1 price alert today' : "{$total} price alerts today";
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.daily-digest');
    }
}
