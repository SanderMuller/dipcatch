<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendDailyDigest;
use App\Models\User;
use App\Support\Config as DipConfig;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;

/**
 * Dispatches SendDailyDigest jobs for users whose local clock has reached the
 * configured send-hour and who haven't received today's digest yet.
 *
 * Runs every minute (see bootstrap/app.php schedule). The minute-granularity
 * is deliberate: at most a 60-second skew between "09:00 local" and the
 * actual dispatch.
 */
#[Signature('dipcatch:dispatch-daily-digests')]
#[Description('Dispatch SendDailyDigest jobs for users due for their daily price-drop email.')]
class DispatchDailyDigestsCommand extends Command
{
    public function handle(): int
    {
        $sendHour = DipConfig::int('dipcatch.digest.send_hour', 9);
        $batchSize = DipConfig::int('dipcatch.digest.batch_size', 500);
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

            // "Already sent today" = last_digest_sent_at falls on the same
            // local date as `localNow`. Comparing local-dates in SQL would
            // need timezone gymnastics, so we use a UTC lower bound: anyone
            // whose last digest is older than the start-of-today-local
            // (converted to UTC) is still due.
            $startOfTodayLocalUtc = $localNow->startOfDay()->setTimezone('UTC');

            $remaining = $batchSize - $dispatched;
            if ($remaining <= 0) {
                break;
            }

            User::query()
                ->where('notify_via_email', true)
                ->where('timezone', $timezone)
                ->where(function (EloquentQueryBuilder $q) use ($startOfTodayLocalUtc): void {
                    $q->whereNull('last_digest_sent_at')
                        ->orWhere('last_digest_sent_at', '<', $startOfTodayLocalUtc);
                })
                ->limit($remaining)
                ->each(function (User $user) use (&$dispatched): void {
                    dispatch(new SendDailyDigest($user))->onQueue('digests');
                    $dispatched++;
                });

            if ($dispatched >= $batchSize) {
                break;
            }
        }

        $this->info("Dispatched {$dispatched} SendDailyDigest jobs.");

        return self::SUCCESS;
    }
}
