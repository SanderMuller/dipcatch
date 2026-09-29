<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Billing\ProUsers;
use App\Models\Shop;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * How much tracking rows overlap, and which query parameters stored URLs
 * carry. Read-only, and counts only: no URL, product or person is printed,
 * only shop hosts and parameter names.
 *
 * It answers two questions before shared page readings and a wider tracking
 * list are trusted: how many fetches a day sharing saves, and which
 * parameters the normalizer still keeps.
 */
#[Signature('dipcatch:shop-url-report')]
#[Description('Report overlapping shop pages and the query parameters in stored shop URLs. Read-only.')]
final class ShopUrlReportCommand extends Command
{
    /** Hosts read from an API or the dataset: no page fetch to share. */
    private const array NO_FETCH_ADAPTERS = ['ah-api', 'checkjebon'];

    public function handle(): int
    {
        $pro = ProUsers::ids()->pluck('users.id')
            ->mapWithKeys(static fn (mixed $id): array => [(is_scalar($id) ? (string) $id : '') => true])
            ->all();
        $hours = [
            'free' => Entitlements::of(Plan::Free)->recheckIntervalHours(),
            'pro' => Entitlements::of(Plan::Pro)->recheckIntervalHours(),
        ];

        $groups = [];
        $params = [];
        $rows = 0;
        $ownReading = 0.0;

        // The rows the scheduler checks: RecheckActiveShopsCommand leaves out
        // reference shops, paused rows and products, and dead rows.
        Shop::query()
            ->scheduled()
            ->with('product:id,user_id')
            ->each(function (Shop $shop) use ($pro, $hours, &$groups, &$params, &$rows, &$ownReading): void {
                $rows++;
                $this->countParams($shop->url, $params);

                if (in_array($shop->adapter_key, self::NO_FETCH_ADAPTERS, strict: true)) {
                    return;
                }

                $interval = isset($pro[(string) $shop->product?->user_id]) ? $hours['pro'] : $hours['free'];
                $key = self::pageKey($shop);

                // A row with its own selectors reads the page its own way: it
                // fetches now and would still fetch with sharing.
                if ($key === null) {
                    $ownReading += 24 / $interval;

                    return;
                }

                $groups[$key][] = $interval;
            });

        $this->report($rows, $groups, $ownReading, $params, $hours);

        return self::SUCCESS;
    }

    /**
     * Rows that would share one reading: the address actually fetched, the
     * adapter that reads it first, the variant and the currency. Null for a
     * row with CSS selectors of its own. Kept in step with the sharing design
     * in specs/shared-page-readings.md.
     */
    private static function pageKey(Shop $shop): ?string
    {
        if ($shop->price_selector !== null || $shop->title_selector !== null || $shop->image_selector !== null) {
            return null;
        }

        return implode("\n", [$shop->url, (string) $shop->adapter_key, (string) $shop->variant_key, strtoupper((string) $shop->currency)]);
    }

    /**
     * @param  array<string, int>  $params
     */
    private function countParams(string $url, array &$params): void
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            $name = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            $params[$name] = ($params[$name] ?? 0) + 1;
        }
    }

    /**
     * @param  array<string, non-empty-list<int>>  $groups  page key => each row's interval in hours
     * @param  float  $ownReading  daily fetches of rows that never share
     * @param  array<string, int>  $params
     * @param  array{free: int, pro: int}  $hours
     */
    private function report(int $rows, array $groups, float $ownReading, array $params, array $hours): void
    {
        // Every page fetch the scheduler makes, the selector rows included.
        $now = $ownReading;
        $shared = $ownReading;
        $overlapping = 0;

        foreach ($groups as $intervals) {
            $now += array_sum(array_map(static fn (int $h): float => 24 / $h, $intervals));
            $shared += 24 / min($intervals);
            $overlapping += count($intervals) > 1 ? 1 : 0;
        }

        arsort($params);

        $this->table(['measure', 'value'], [
            ['recheck interval, free / pro (hours)', "{$hours['free']} / {$hours['pro']}"],
            ['active tracked rows', $rows],
            ['pages fetched (shareable key)', count($groups)],
            ['page fetches a day from rows with own selectors', (int) round($ownReading)],
            ['pages tracked by 2+ rows', $overlapping],
            ['page fetches a day now', (int) round($now)],
            ['page fetches a day with sharing', (int) round($shared)],
        ]);

        $this->table(['query parameter', 'rows'], array_map(
            static fn (string $name, int $count): array => [$name, $count],
            array_keys(array_slice($params, 0, 40, true)),
            array_slice($params, 0, 40, true),
        ));
    }
}
