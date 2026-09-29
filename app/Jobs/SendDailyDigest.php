<?php declare(strict_types=1);

namespace App\Jobs;

use App\Mail\DailyDigestMail;
use App\Models\PriceDropEvent;
use App\Models\TargetPriceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

/**
 * Build + send the daily digest of drops and reached targets for a single
 * user. Replaces the per-alert email path (Filament bell + web push remain
 * real-time).
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
        // Floored at a day. `.env.example` advertises this knob, and a 0 or
        // negative value collapses the window to `fired_at > $until AND
        // fired_at <= $until` — empty on every run, for every user, with no
        // mail, no cursor movement and no error to say why.
        $lookbackDays = max(1, Config::integer('dipcatch.digest.lookback_days'));
        $now = CarbonImmutable::now();
        // The window's end and the cursor, one value so the two cannot drift
        // apart. A minute behind the clock: an event is stamped `fired_at`
        // before its transaction commits, so one stamped just before this run
        // may not be visible yet. It falls in the next window instead of
        // behind the cursor.
        $until = $now->subMinute();
        // Coalesce null to "24h ago" for first-ever digests; cap at the
        // configured lookback to avoid emailing a giant backlog if mail
        // bounced for days.
        $minSince = $now->subDays($lookbackDays);
        $processedUntil = $this->user->digest_processed_until;
        $since = $processedUntil instanceof CarbonImmutable
            ? $processedUntil->max($minSince)
            : $now->subDay()->max($minSince);

        $events = PriceDropEvent::query()
            ->where('user_id', $this->user->id)
            ->where('fired_at', '>', $since)
            ->where('fired_at', '<=', $until)
            ->with(['product', 'triggeredByShop', 'priceCheck'])
            ->oldest('fired_at')
            ->get();

        $reached = TargetPriceEvent::query()
            ->where('user_id', $this->user->id)
            ->where('fired_at', '>', $since)
            ->where('fired_at', '<=', $until)
            ->with(['product', 'shop'])
            ->oldest('fired_at')
            ->get();

        // "Processed up to", so it moves on an empty window too: the
        // dispatcher reads it as "done today".
        //
        // Claimed BEFORE Mail::send so a crash or transient mail failure
        // between send and cursor save doesn't double-deliver on retry.
        // Trade-off: a failed mail loses that batch from the email channel —
        // but those drops are still in the DB and were already delivered live
        // via the Filament bell + web push channels.
        $this->user->forceFill(['digest_processed_until' => $until])->save();

        if ($events->isEmpty() && $reached->isEmpty()) {
            return;
        }

        Mail::to($this->user->email)->send(new DailyDigestMail($this->user, $events, $reached));
    }
}
