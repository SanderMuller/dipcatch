<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CanaryOutcome;
use App\Jobs\RunAdapterCanary;
use App\Models\AdapterCanaryResult;
use App\Support\CanaryEntries;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Fetch one known product page per host adapter and report whether that
 * adapter still reads it.
 *
 * Two adapter failures exist and the application sees neither on its own. One
 * breaks loudly — a matched host adapter returns `failed` after a redesign,
 * which stores a ParseError on the shops that use it, and no shop uses an
 * adapter nobody tracks. The other breaks quietly — the adapter still finds a
 * number and the number is wrong. The price-move arm is the only thing that
 * can see the second one.
 *
 * Nothing here writes a price_check, touches a shop, or influences an alert.
 * A run without --force queues a {@see RunAdapterCanary} job per entry; the
 * fetches run on the queue.
 */
#[Signature('dipcatch:canary {--force : Fetch even outside the configured environments, here rather than on the queue, and print each outcome}')]
#[Description('Check that every host price adapter still reads a known product page.')]
final class RunAdapterCanaryCommand extends Command
{
    public function handle(): int
    {
        $configured = CanaryEntries::configured();

        // Before the empty-list return: the last adapter leaving the list
        // must take its row with it, or a retired entry reports forever.
        AdapterCanaryResult::query()
            ->whereNotIn(AdapterCanaryResult::ADAPTER, array_keys($configured))
            ->delete();

        if ($configured === []) {
            $this->components->warn('No canary URLs are configured.');

            return self::SUCCESS;
        }

        if (! $this->mayFetch()) {
            $this->components->warn('The canary does not fetch in this environment. Pass --force to override.');

            return self::SUCCESS;
        }

        // A person passing --force is waiting for the answers, so those run
        // here and print. A run without it queues them.
        $byHand = $this->option('force') === true;

        foreach ($configured as $adapter => $url) {
            if (! $byHand) {
                dispatch(new RunAdapterCanary($adapter, $url));

                continue;
            }

            dispatch_sync(new RunAdapterCanary($adapter, $url));

            $outcome = AdapterCanaryResult::query()->where(AdapterCanaryResult::ADAPTER, $adapter)->first()?->outcome;
            $this->components->twoColumnDetail($adapter, $outcome instanceof CanaryOutcome ? $outcome->value : '-');
        }

        if (! $byHand) {
            $this->components->info(sprintf('Queued %d canary checks.', count($configured)));
        }

        return self::SUCCESS;
    }

    private function mayFetch(): bool
    {
        // `--force` is for a person checking a new adapter by hand. It cannot
        // reach the test suite: a test that fetched twenty-one real shops
        // would be a slow, flaky way to annoy every one of them.
        if ($this->option('force') === true && ! app()->environment('testing')) {
            return true;
        }

        /** @var list<string> $environments */
        $environments = Config::array('canary.environments');

        return app()->environment($environments);
    }
}
