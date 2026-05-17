<?php declare(strict_types=1);

namespace App\Jobs;

use App\Mail\PriceDropDigestMail;
use App\Models\PriceDropEvent;
use App\Models\User;
use App\Support\Config as DipConfig;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Build + send the daily price-drop digest for a single user. Replaces the
 * per-drop email path (see specs/email-digest.md).
 *
 * Dispatched once per user per local day by DispatchDailyDigestsCommand. The
 * uniqueness key includes the user's local date, so a re-dispatch within the
 * same local day is a no-op.
 */
class SendDailyDigest implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> Retry backoff in seconds. */
    public array $backoff = [60, 300, 1800];

    public function __construct(public User $user) {}

    public function uniqueId(): string
    {
        // Local-date-aware: prevents a same-day re-dispatch even if the
        // command fires twice (e.g. scheduler restart). The command's "is
        // 09:00 yet" check is the primary gate; this is belt-and-braces.
        $localDate = CarbonImmutable::now($this->resolveTimezone())->format('Y-m-d');

        return "daily-digest:{$this->user->id}:{$localDate}";
    }

    public function uniqueFor(): int
    {
        // ~24h — long enough to span the digest's window, short enough to
        // release the lock before the next day's run.
        return 23 * 60 * 60;
    }

    public function handle(): void
    {
        $lookbackDays = DipConfig::int('dipcatch.digest.lookback_days', 7);
        // Coalesce null to "24h ago" for first-ever digests; cap at the
        // configured lookback to avoid emailing a giant backlog if mail
        // bounced for days.
        $minSince = CarbonImmutable::now()->subDays($lookbackDays);
        $lastSent = $this->user->last_digest_sent_at;
        $since = $lastSent instanceof CarbonImmutable
            ? $lastSent->max($minSince)
            : CarbonImmutable::now()->subDay()->max($minSince);

        /** @var Collection<int, PriceDropEvent> $events */
        $events = PriceDropEvent::query()
            ->where('user_id', $this->user->id)
            ->where('fired_at', '>', $since)
            ->with(['product', 'triggeredByShop'])
            ->orderBy('fired_at')
            ->get();

        if ($events->isEmpty()) {
            // Don't send empty digests; don't bump last_digest_sent_at so
            // the next non-empty window will still pick up these events.
            return;
        }

        $grouped = $events
            ->groupBy('product_id')
            ->map(function (Collection $eventsForProduct): array {
                $first = $eventsForProduct->first();
                assert($first instanceof PriceDropEvent);

                return [
                    'product' => $first->product,
                    'events' => $eventsForProduct->values(),
                ];
            });

        Mail::to($this->user->email)->send(new PriceDropDigestMail(
            user: $this->user,
            grouped: $grouped,
            totalDrops: $events->count(),
        ));

        $this->user->forceFill(['last_digest_sent_at' => CarbonImmutable::now()])->save();
    }

    private function resolveTimezone(): string
    {
        $tz = $this->user->timezone;

        return is_string($tz) && $tz !== '' ? $tz : 'Europe/Amsterdam';
    }
}
