<?php declare(strict_types=1);

namespace App\Support;

final class LocaleCurrency
{
    /**
     * Map of language tag → ISO 4217 currency code.
     * Long tags are checked first; bare language code is the fallback.
     *
     * @var array<string, string>
     */
    private const array MAP = [
        'nl-NL' => 'EUR',
        'nl-BE' => 'EUR',
        'nl' => 'EUR',
        'de-DE' => 'EUR',
        'de-AT' => 'EUR',
        'de' => 'EUR',
        'fr-FR' => 'EUR',
        'fr-BE' => 'EUR',
        'fr' => 'EUR',
        'es-ES' => 'EUR',
        'es' => 'EUR',
        'it-IT' => 'EUR',
        'it' => 'EUR',
        'pt-PT' => 'EUR',
        'pt-BR' => 'BRL',
        'pt' => 'EUR',
        'en-GB' => 'GBP',
        'en-IE' => 'EUR',
        'en-US' => 'USD',
        'en-CA' => 'CAD',
        'en-AU' => 'AUD',
        'en-NZ' => 'NZD',
        'en' => 'USD',
        'ja-JP' => 'JPY',
        'ja' => 'JPY',
        'zh-CN' => 'CNY',
        'zh-TW' => 'TWD',
        'zh-HK' => 'HKD',
        'zh' => 'CNY',
        'ko-KR' => 'KRW',
        'ko' => 'KRW',
        'pl-PL' => 'PLN',
        'pl' => 'PLN',
        'sv-SE' => 'SEK',
        'sv' => 'SEK',
        'da-DK' => 'DKK',
        'da' => 'DKK',
        'nb-NO' => 'NOK',
        'no' => 'NOK',
        'cs-CZ' => 'CZK',
        'cs' => 'CZK',
    ];

    public static function guess(?string $acceptLanguage, string $fallback = 'EUR'): string
    {
        if ($acceptLanguage === null || trim($acceptLanguage) === '') {
            return $fallback;
        }

        foreach (self::parse($acceptLanguage) as $tag) {
            if (isset(self::MAP[$tag])) {
                return self::MAP[$tag];
            }

            $primary = explode('-', $tag, 2)[0];
            if (isset(self::MAP[$primary])) {
                return self::MAP[$primary];
            }
        }

        return $fallback;
    }

    /**
     * Parse an Accept-Language header into ordered language tags (highest q-value first).
     *
     * @return list<string>
     */
    private static function parse(string $header): array
    {
        $entries = [];

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $segments = explode(';', $part);
            $tag = trim($segments[0]);
            if ($tag === '' || $tag === '*') {
                continue;
            }

            $quality = 1.0;
            foreach (array_slice($segments, 1) as $segment) {
                $segment = trim($segment);
                if (str_starts_with($segment, 'q=')) {
                    $quality = (float) substr($segment, 2);
                }
            }

            $entries[] = ['tag' => self::normalize($tag), 'q' => $quality];
        }

        usort($entries, fn (array $a, array $b): int => $b['q'] <=> $a['q']);

        return array_map(fn (array $entry): string => $entry['tag'], $entries);
    }

    private static function normalize(string $tag): string
    {
        $parts = explode('-', $tag, 2);
        $primary = strtolower($parts[0]);
        if (! isset($parts[1])) {
            return $primary;
        }

        return $primary . '-' . strtoupper($parts[1]);
    }
}
