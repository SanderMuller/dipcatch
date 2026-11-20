<?php declare(strict_types=1);

namespace App\Analytics;

/**
 * Where a guest first came from: the external referrer host and the UTM
 * values of the first counted page load that had any of them.
 */
final readonly class SignupSource
{
    public function __construct(
        public ?string $referrerHost = null,
        public ?string $utmSource = null,
        public ?string $utmMedium = null,
        public ?string $utmCampaign = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $string = static fn (string $key): ?string => isset($values[$key]) && is_string($values[$key]) && $values[$key] !== '' ? $values[$key] : null;

        return new self($string('referrer_host'), $string('utm_source'), $string('utm_medium'), $string('utm_campaign'));
    }

    public function isEmpty(): bool
    {
        return $this->referrerHost === null && $this->utmSource === null && $this->utmMedium === null && $this->utmCampaign === null;
    }

    /**
     * @return array{referrer_host: ?string, utm_source: ?string, utm_medium: ?string, utm_campaign: ?string}
     */
    public function toArray(): array
    {
        return [
            'referrer_host' => $this->referrerHost,
            'utm_source' => $this->utmSource,
            'utm_medium' => $this->utmMedium,
            'utm_campaign' => $this->utmCampaign,
        ];
    }
}
