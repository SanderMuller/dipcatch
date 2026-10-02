<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendDailyDigest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Support\Facades\Config;

/**
 * Dispatches SendDailyDigest jobs for users whose local clock has reached the
 * configured send-hour and whose digest has not run yet today.
 *
 * Runs hourly, on the hour (see bootstrap/app.php schedule). The test is
 * hour-granular, so the digest goes out on the first run at or after the send
 * hour: on time in a whole-hour timezone, up to 45 minutes late in the others.
 */
#[Signature('dipcatch:dispatch-daily-digests')]
#[Description('Dispatch SendDailyDigest jobs for users due for their daily price-drop email.')]
final class DispatchDailyDigestsCommand extends Command
{
    public function handle(): int
    {
        $sendHour = Config::integer('dipcatch.digest.send_hour');
        $nowUtc = CarbonImmutable::now('UTC');

        // Per-timezone dispatch: each timezone has its own "is it 09:00 here
        // now AND has today's digest not been sent yet" test. Grouping by
        // timezone first lets us compute the local-time predicates once per
        // group instead of per row.
        $timezones = User::query()
            ->where('notify_via_email', true)
            ->distinct()
            ->pluck('timezone');

        $dispatched = 0;

        foreach ($timezones as $timezone) {
            if (! is_string($timezone) || $timezone === '') {
                continue;
            }

            $localNow = $nowUtc->setTimezone($timezone);
            if ($localNow->hour < $sendHour) {
                // 09:00 local hasn't arrived yet today.
                continue;
            }

            // "Already ran today" = digest_processed_until falls on the same
            // local date as `localNow`. Comparing local-dates in SQL would
            // need timezone gymnastics, so we use a UTC lower bound: anyone
            // whose digest last ran before the start-of-today-local
            // (converted to UTC) is still due.
            $startOfTodayLocalUtc = $localNow->startOfDay()->setTimezone('UTC');

            $digestDate = $localNow->format('Y-m-d');

            User::query()
                ->where('notify_via_email', true)
                ->where('timezone', $timezone)
                ->where(function (EloquentQueryBuilder $q) use ($startOfTodayLocalUtc): void {
                    $q->whereNull('digest_processed_until')
                        ->orWhere('digest_processed_until', '<', $startOfTodayLocalUtc);
                })
                // No batch cap. Each account runs once per local day, and a
                // cap spent itself on accounts whose job was still queued: the
                // unique lock skips their re-dispatch without a word, and the
                // same low ids filled every tick while the queue was behind.
                // Workers set the sending pace.
                ->lazyById()
                ->each(function (User $user) use ($digestDate, &$dispatched): void {
                    dispatch(new SendDailyDigest($user, $digestDate));
                    $dispatched++;
                });
        }

        $this->info("Dispatched {$dispatched} SendDailyDigest jobs.");

        return self::SUCCESS;
    }
}
