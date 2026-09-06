<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Widgets;

use App\Models\Product;
use Filament\Support\RawJs;

/**
 * The Chart.js options for {@see PriceHistoryChart}.
 *
 * Kept out of the widget so the axis rules can be read — and tested —
 * without the data building around them. A PHP array cannot carry a JS
 * function, so the tick and tooltip callbacks ship as RawJs. The tooltip
 * formats with the browser's own ICU, using the `currency` field every
 * dataset carries — the same CLDR rules PHP intl applies server-side.
 */
final class PriceHistoryChartOptions
{
    public static function forProduct(?Product $product): RawJs
    {
        return self::make($product instanceof Product && self::statesAnyPackSize($product));
    }

    /**
     * Declaring the per-unit axis when nothing plots on it costs real width —
     * about a tenth of the plot on a phone — for an axis with no line.
     *
     * The question is asked of the product, not of the range in view: a range
     * change updates the datasets but never re-sends these options, so an
     * axis decided per range would leave Chart.js to invent an undeclared one
     * — unlabelled, on the wrong side, its gridlines over the plot.
     */
    private static function statesAnyPackSize(Product $product): bool
    {
        foreach ($product->shops as $shop) {
            if ($shop->packUnitLabel() !== null) {
                return true;
            }
        }

        return false;
    }

    private static function make(bool $withUnitAxis): RawJs
    {
        $unitScale = $withUnitAxis
            ? "unit: { position: 'right', title: { display: true, text: 'Per unit' }, grid: { drawOnChartArea: false } },"
            : '';

        // Nowdoc, so the JS template literals below survive PHP untouched;
        // the one dynamic part goes in by placeholder.
        return RawJs::make(str_replace('__UNIT_SCALE__', $unitScale, <<<'JS'
            {
                scales: {
                    x: {
                        ticks: {
                            autoSkip: true,
                            maxTicksLimit: 6,
                            maxRotation: 0,
                            // The label carries the full stamp so the tooltip
                            // can state the minute, but a phone-width axis has
                            // room for the date only.
                            callback(value) {
                                const label = this.getLabelForValue(value);

                                return typeof label === 'string' ? label.slice(0, 10) : label;
                            },
                        },
                    },
                    y: {
                        position: 'left',
                        title: { display: true, text: 'Price' },
                    },
                    __UNIT_SCALE__
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const value = ctx.parsed.y;
                                if (value === null || value === undefined) {
                                    return ctx.dataset.label;
                                }

                                const money = new Intl.NumberFormat('en-US', {
                                    style: 'currency',
                                    currency: ctx.dataset.currency,
                                }).format(value);

                                return `${ctx.dataset.label}: ${money}`;
                            },
                        },
                    },
                },
            }
            JS));
    }
}
