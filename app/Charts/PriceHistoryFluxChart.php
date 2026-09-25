<?php declare(strict_types=1);

namespace App\Charts;

use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;

/**
 * Turns {@see PriceHistorySeries::data()} into rows a Flux line chart can plot.
 *
 * Null prices stay as gaps. A notified point is omitted when no alert fired
 * on that stamp.
 */
final class PriceHistoryFluxChart
{
    /**
     * @param  array{labels: list<string>, price: list<float|null>, unit: array{unit: string, points: list<float|null>}|null, notified: list<float|null>, bundleConditions: list<?string>}  $data
     * @return array{rows: list<array<string, mixed>>, currency: string, unit: ?string, unitDecimals: int, hasNotified: bool, hasBundles: bool, unitCoverage: float}
     */
    public static function fromData(array $data, string $currency): array
    {
        $rows = self::holdingUntilNextChange(self::rows(
            $data['labels'],
            $data['price'],
            $data['unit']['points'] ?? null,
            $data['notified'],
            $data['bundleConditions'],
        ));

        return [
            'rows' => $rows,
            'currency' => $currency,
            'unit' => $data['unit']['unit'] ?? null,
            'unitDecimals' => self::unitDecimals($rows),
            'hasNotified' => array_any($rows, fn (array $row): bool => isset($row['notified'])),
            'hasBundles' => array_any($rows, fn (array $row): bool => isset($row['bundle'])),
            'unitCoverage' => UnitLineCoverage::of($rows),
        ];
    }

    /**
     * @param  list<string>  $labels
     * @param  list<float|null>  $price
     * @param  list<float|null>|null  $unit
     * @param  list<float|null>  $notified
     * @param  list<?string>  $bundleConditions
     * @return list<array<string, mixed>>
     */
    private static function rows(array $labels, array $price, ?array $unit, array $notified, array $bundleConditions): array
    {
        $rows = [];

        foreach ($labels as $index => $stamp) {
            $row = [
                'date' => self::date(is_string($stamp) ? $stamp : ''),
                'price' => is_numeric($price[$index] ?? null) ? (float) $price[$index] : null,
            ];

            if ($unit !== null) {
                $row['unit'] = is_numeric($unit[$index] ?? null) ? (float) $unit[$index] : null;
            }

            if (is_numeric($notified[$index] ?? null)) {
                $row['notified'] = (float) $notified[$index];

                // The alert states a pack price. On the per-unit line the
                // marker sits on that moment's unit price instead.
                if (isset($row['unit'])) {
                    $row['notifiedUnit'] = $row['unit'];
                }
            }

            if (is_string($bundleConditions[$index] ?? null)) {
                $row['bundle'] = $bundleConditions[$index];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Flux line charts have no stepped curve. Repeat the current price one
     * second before the next stamp so the line holds, then jumps.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function holdingUntilNextChange(array $rows): array
    {
        $expanded = [];
        $lastIndex = count($rows) - 1;

        foreach ($rows as $index => $row) {
            $expanded[] = $row;

            if ($index === $lastIndex) {
                continue;
            }

            $next = $rows[$index + 1];
            $priceChanged = ($row['price'] ?? null) !== ($next['price'] ?? null);
            $unitChanged = ($row['unit'] ?? null) !== ($next['unit'] ?? null);

            if (! $priceChanged && ! $unitChanged) {
                continue;
            }

            $holdAt = self::oneSecondBefore(is_string($next['date'] ?? null) ? $next['date'] : '');

            if ($holdAt === null) {
                continue;
            }

            $hold = $row;
            $hold['date'] = $holdAt;
            unset($hold['notified'], $hold['notifiedUnit']);
            $expanded[] = $hold;
        }

        return $expanded;
    }

    private static function oneSecondBefore(string $iso): ?string
    {
        if ($iso === '') {
            return null;
        }

        return CarbonImmutable::parse($iso)->subSecond()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Four decimals while every unit price is under 1, as
     * {@see MoneyFormatter::unitPrice()} writes them: two
     * cannot tell €0.0283 from €0.0249 a tablet.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function unitDecimals(array $rows): int
    {
        $units = array_filter(array_column($rows, 'unit'), is_float(...));

        return $units !== [] && max($units) < 1 ? 4 : 2;
    }

    private static function date(string $stamp): string
    {
        $parsed = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $stamp);

        return $parsed instanceof CarbonImmutable
            ? $parsed->utc()->format('Y-m-d\TH:i:s\Z')
            : $stamp;
    }
}
