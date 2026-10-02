<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\ApiService;
use App\Models\ApiUsageDay;
use Closure;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Number;

/** Today's calls to each outside service, with the last 7 days; per purpose in ApiUsagePurposesWidget. */
final class ApiUsageWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = 3;

    protected ?string $heading = 'Outside services';

    protected ?string $description = 'Days run in UTC. A refusal is each call a daily cap blocked; the caps run 24 hours from their first use, not per calendar day.';

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        /** @var Collection<int, ApiUsageDay> $days */
        $days = ApiUsageDay::query()->where('day', '>=', now()->subDays(6)->toDateString())->get();

        return array_map(fn (ApiService $service): Stat => $this->stat($service, $days->filter(fn (ApiUsageDay $day): bool => $day->service === $service)), ApiService::cases());
    }

    /**
     * @param  Collection<int, ApiUsageDay>  $days  The last 7 days of this service.
     */
    private function stat(ApiService $service, Collection $days): Stat
    {
        $today = $days->filter(fn (ApiUsageDay $day): bool => $day->day->isToday());
        $failures = self::total($today, fn (ApiUsageDay $day): int => $day->failures);
        $refusals = self::total($today, fn (ApiUsageDay $day): int => $day->refusals);

        $details = array_filter([
            $service === ApiService::Serper ? 'cap ' . Config::integer('dipcatch.web_discovery.daily_search_limit') . ' a day' : null,
            $failures > 0 ? "{$failures} failed" : null,
            $refusals > 0 ? "{$refusals} refused by a cap" : null,
            $service === ApiService::TypeSafe ? Number::abbreviate(self::total($today, fn (ApiUsageDay $day): int => $day->input_tokens + $day->output_tokens)) . ' tokens' : null,
            'last 7 days: ' . self::total($days, fn (ApiUsageDay $day): int => $day->calls),
        ]);

        return Stat::make($service->label() . ' today', self::total($today, fn (ApiUsageDay $day): int => $day->calls))
            ->description(implode(' · ', $details))
            ->chart($this->perDay($days));
    }

    /**
     * Calls per day, oldest first, for the sparkline.
     *
     * @param  Collection<int, ApiUsageDay>  $days
     * @return list<float>
     */
    private function perDay(Collection $days): array
    {
        return array_map(
            fn (int $daysAgo): float => self::total($days->filter(fn (ApiUsageDay $day): bool => $day->day->isSameDay(now()->subDays($daysAgo))), fn (ApiUsageDay $day): int => $day->calls),
            range(6, 0),
        );
    }

    /**
     * @param  Collection<int, ApiUsageDay>  $days
     * @param  Closure(ApiUsageDay): int  $amount
     */
    private static function total(Collection $days, Closure $amount): int
    {
        return array_sum(array_map($amount, $days->all()));
    }
}
