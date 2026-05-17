<?php declare(strict_types=1);

namespace App\Mail;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Daily price-drop digest for a single user — one message summarising every
 * PriceDropEvent fired since their previous digest, grouped by product.
 *
 * SendDailyDigest hands us a pre-grouped collection (keyed by product_id)
 * already filtered + ordered by the caller; this class is a thin shell over
 * the Blade view.
 */
class PriceDropDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<(int|string), array{product: ?Product, events: \Illuminate\Database\Eloquent\Collection<int, Model>}>  $grouped
     */
    public function __construct(
        public User $user,
        public Collection $grouped,
        public int $totalDrops,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->totalDrops === 1
            ? '1 price drop today'
            : "{$this->totalDrops} price drops today";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.price-drop-digest');
    }
}
