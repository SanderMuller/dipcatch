<?php declare(strict_types=1);

namespace App\Analytics\Enums;

/**
 * What a row in `page_view_daily_stats` is grouped by. `Total` has one row
 * per section and day, with an empty value.
 */
enum StatDimension: string
{
    case Total = 'total';
    case Path = 'path';
    case Route = 'route';
    case Referrer = 'referrer';
    case UtmSource = 'utm_source';
    case UtmCampaign = 'utm_campaign';
    case Country = 'country';
    case Device = 'device';

    /**
     * The SQL value this dimension groups `page_views` by, with a missing
     * value as an empty string. A literal per case, never built from input.
     *
     * @return literal-string
     */
    public function valueExpression(): string
    {
        return match ($this) {
            self::Total => "''",
            self::Path => 'path',
            self::Route => "COALESCE(route_name, '')",
            self::Referrer => "COALESCE(referrer_host, '')",
            self::UtmSource => "COALESCE(utm_source, '')",
            self::UtmCampaign => "COALESCE(utm_campaign, '')",
            self::Country => "COALESCE(country, '')",
            self::Device => 'device',
        };
    }

    /**
     * @return literal-string
     */
    public function groupExpression(): string
    {
        return $this === self::Total ? 'section' : 'section, ' . $this->valueExpression();
    }
}
