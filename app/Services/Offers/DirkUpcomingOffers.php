<?php declare(strict_types=1);

namespace App\Services\Offers;

/**
 * Dirk's announced offers. Next week's sit under "vanaf woensdag"; after
 * Wednesday's turnover a weekend offer that starts on Friday may be listed
 * only among the current offers, so both pages are read.
 */
final readonly class DirkUpcomingOffers extends NuxtOffersPages
{
    public const string NEXT_WEEK_URL = 'https://www.dirk.nl/aanbiedingen/vanaf-woensdag';

    public const string CURRENT_URL = 'https://www.dirk.nl/aanbiedingen';

    protected function urls(): array
    {
        return [self::NEXT_WEEK_URL, self::CURRENT_URL];
    }

    protected function cacheKey(): string
    {
        return 'dirk:announced-offers:v1';
    }

    /** Dirk's `textPriceSign` is a template id (`ACTIE_`, `VR, ZA & ZO_actie`), so the price names the deal. */
    protected function readsText(): bool
    {
        return false;
    }
}
