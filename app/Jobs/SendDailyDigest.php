<?php declare(strict_types=1);

namespace App\Jobs;

use App\Mail\PriceDropDigestMail;
use App\Models\PriceDropEvent;
use App\Models\User;
use App\Support\Config as DipConfig;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Mail;

/**
 * Build + send the daily price-drop digest for a single user. Replaces the
 * per-drop email path (Filament bell + web push remain real-time).
 *
 * Dispatched once per user per local day by DispatchDailyDigestsCommand. The
 * dispatcher passes the local digest-date string so the uniqueness key is
 * fixed at dispatch time — not recomputed from mutable `$user->timezone`
 * inside handle(), which would risk drift around midnight boundaries if the
 * user changed timezone between dispatch and run.
 */
#[Backoff([60, 300, 1800])]
#[Tries(3)]
final class SendDailyDigest implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $user,
        public string $digestDate,
    ) {}

    public function uniqueId(): string
    {
        return "daily-digest:{$this->user->id}:{$this->digestDate}";
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

        $events = PriceDropEvent::query()
            ->where('user_id', $this->user->id)
            ->where('fired_at', '>', $since)
            ->with(['product', 'triggeredByShop', 'priceCheck'])
            ->oldest('fired_at')
            ->get();

        if ($events->isEmpty()) {
            // Don't send empty digests; don't bump last_digest_sent_at so
            // the next non-empty window will still pick up these events.
            return;
        }

        // Claim the window BEFORE Mail::send so a crash or transient mail
        // failure between send and cursor save doesn't double-deliver on
        // retry. Trade-off: a failed mail loses that batch from the email
        // channel — but those drops are still in the DB and were already
        // delivered live via the Filament bell + web push channels.
        $this->user->forceFill(['last_digest_sent_at' => CarbonImmutable::now()])->save();

        Mail::to($this->user->email)->send(new PriceDropDigestMail($this->user, $events));
    }
}
