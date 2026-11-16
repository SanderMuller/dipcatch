<?php declare(strict_types=1);

namespace App\Services\Offers;

/**
 * DekaMarkt's announced offers, from its "volgende week" page. The page is
 * priced for the store an anonymous visitor gets, which is the store the
 * product page shows too.
 */
final readonly class DekaMarktUpcomingOffers extends NuxtOffersPages
{
    public const string URL = 'https://www.dekamarkt.nl/aanbiedingen/volgende-week';

    protected function urls(): array
    {
        return [self::URL];
    }

    protected function cacheKey(): string
    {
        return 'dekamarkt:announced-offers:v1';
    }
}
